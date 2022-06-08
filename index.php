<?php

include "vendor/autoload.php";

use Dotenv\Dotenv;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\RowIterator;


const FILE_INPUT_EXCEL = 'excel';                // Наименование файла в теле POST
const MIN_BARCODE_DIGIT_COUNT = 6;                  // Меньше этого значения - высветится предупреждение

const LOG_FOLDER_ROOT = 'log';                      // Произвольное имя папки для хранения логов

const FILES_SIZE_KEY = 'size';                      // Поле содержащий размер присланного файла

const POST_SUBMIT = 'submit';

$alertClass = "danger";     // Цвет блока alert
$alertMsg = "";             // Содержание блока alert


// Выполнение преднастроек скрипта

preSettings();

// Выполняется, если отправили форму
if (isset($_POST[POST_SUBMIT])) {

    logStartMain(); // Логирует старт работы с присланными данными

    main($alertClass, $alertMsg); // Вызов главной функции
}


// ---------------------------------------------- Функции
/**
 * Выполнение преднастроек скрипта
 *
 * @return void
 *
 * @throws ErrorException
 */
function preSettings(): void
{
// Из файла .env берем значения для FTP соединения
    try {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    } catch (Exception $e) {
        printf("Error: %s in %s(%d)", $e->getMessage(), $e->getFile(), $e->getLine());
        exit(1);
    }

// Установка часового пояса как в примере (где бы не выполнялся скрипт - одинаковое время)

    date_default_timezone_set('Europe/Moscow');

// Преобразуют Warning в Exception. Ошибки Ftp могут выкидывать Warning. Имплементировано для логирования содержимого

    set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line, array $err_context) {
        throw new ErrorException($err_msg, 0, $err_severity, $err_file, $err_line);
    }, E_WARNING);
}


/**
 * Главная функция
 *
 * @return void
 */
function main(&$alertClass, &$alertMsg)
{
    try {

        if (!isset($_FILES[FILE_INPUT_EXCEL]) || 0 == $_FILES[FILE_INPUT_EXCEL][FILES_SIZE_KEY]) {
            throw new Exception("Таблица не прислана");
        }

        // Создание и отправка XML
        processData(FILE_INPUT_EXCEL, $alertMsg);

        if (empty($alertMsg)) {
            $alertClass = "success";
            $alertMsg = "Файл успешно загружен";
        } else {
            $alertClass = "warning";
            $alertMsg = "Файл загружен, но: $alertMsg";
        }

    } catch (DOMException $e) {
        logMsg($e->getMessage());
        http_response_code(400);
        $alertMsg = "Прислана таблица с несоответствующим содержанием";
    } catch (Exception $e) {
        http_response_code(400);
        $alertMsg = $e->getMessage();
    }

    logMsg("Alert message: $alertMsg");
}


/**
 * Обрабатывает таблицу, формирует xml, заливает на FTP
 *
 * @param string $excelPostName Имя файла экселя из POST
 * @param string $alertMsg Сообщение с ошибкой
 *
 * @return void
 *
 * @throws DOMException
 * @throws Exception Тут должны быть только исключения только явно вызванные в коде
 */
function processData(string $excelPostName, string &$alertMsg)
{
    // Адрес где будет храниться временный созданный файл Xml для передачи на фтп
    $localXmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $_ENV['RESULT_FILENAME'];

    // Получение искомого массива из экселя
    $arrayFromExcel = excelToArray($excelPostName, $alertMsg);

    // Создание xml файла в указанном пути
    createXml($arrayFromExcel, $localXmlPath);

    // Отправка файла на FTP сервер
    uploadToFtp($_ENV['RESULT_FILENAME'], $localXmlPath);
}

/**
 * Преобразование таблицы в массив.
 *
 * Игнорирует первую строку (шапка). Двумерный массив, где каждый элемент - отдельная строка таблицы. Каждый из этих
 * массивов - массив, первый элемент которого - id, остальные баркоды.
 * Пример возврата: [ [2122131, 2312312, ...], [2131253, 4132123,...], ...]
 *
 * @param string $excelPostName Имя файла экселя из POST
 * @param string $alertMsg Сообщение с ошибкой
 * @return array
 */
function excelToArray(string $excelPostName, string &$alertMsg): array
{
    $arrayFromExcel = []; // То что будем возвращать

    // Преобразуем эксель
    $spreadsheet = IOFactory::load($_FILES[$excelPostName]["tmp_name"]);
    $worksheet = $spreadsheet->getActiveSheet();

    // Дополнительные переменные
    $headerIsPassed = false;    // Используется для пропуска первой строки экселя
    $setIds = [];               // Здесь храним все set id - на проверку повторений
    $identicalSetIds = [];      // Здесь храним все повторные set id - таких не должно быть
    $shortValues = [];        // Здесь храним все короткие баркоды - таких не должно быть


    /**
     * @var $row RowIterator
     */
    foreach ($worksheet->getRowIterator() as $row) { // Здесь перебираются строки

        if (!$headerIsPassed) { // Чтобы пропустить первую строку из файла (шапка)
            $headerIsPassed = true;
            continue;
        }

        $rowAsArray = []; // Для содержимого строки экселя

        $isFirstCell = true; // В первом столбце айдишники - к ним немного другие действие

        /**
         * @var $cell RowCellIterator
         */
        foreach ($row->getCellIterator() as $cell) { // Добавляем каждую ячейку строки отдельными элементами в массив

            if (empty($cell->getValue())) {
                break;
            }

            $cellValue = $cell->getValue();

            if (strlen(strval($cellValue)) <= MIN_BARCODE_DIGIT_COUNT) { // Проверка если баркод меньше кол-ва символов
                $shortValues[] = $cellValue;
                if ($isFirstCell) {
                    break;
                }
                continue;
            }

            if ($isFirstCell) { // Проверка айдишник ли это

                if (in_array($cellValue, $setIds)) { // Если повторяется Set ID - пропускаем строку и оповещаем о повторе
                    $identicalSetIds[] = $cellValue;
                    break;
                }

                $setIds[] = $cellValue; // Добавили в список айдишников
                $isFirstCell = false; // Следующие из этой строки уже будут не айди

            }

            $rowAsArray[] = $cellValue;
        }

        if (!empty($rowAsArray)) {
            $arrayFromExcel[] = $rowAsArray; // Массив содержащую строку таблицы закидываем в общий массив отдельным элементом
        }

    }

    if (!empty($identicalSetIds)) {
        $alertMsg = sprintf("%s <br> %sВстретились неуникальные ID: %s", $alertMsg, PHP_EOL, implode(", ", array_unique($identicalSetIds)));
    }

    if (!empty($shortValues)) {
        $alertMsg = sprintf("%s <br> %sВстретились баркоды меньше %d символов: %s", $alertMsg, PHP_EOL, MIN_BARCODE_DIGIT_COUNT, implode(", ", array_unique($shortValues)));
    }

    return $arrayFromExcel;
}


/**
 * Создает готовый XML-файл для выгрузки по указанному локальному пути
 *
 * @param array $arrayFromExcel Двумерный массив, каждый - строка из экселя. Подробнее в описании функции формирования
 * @param string $localXmlPath Путь куда сохранить созданный файл
 *
 * @return void
 *
 * @throws DOMException
 */
function createXml(array $arrayFromExcel, string $localXmlPath): void
{
    // Создание объекта для сохранения итогового XML-файла
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->xmlVersion = '1.0';
    $dom->formatOutput = true;

    // Создание родительского элемента 1
    $root1 = $dom->createElement('sets');


    foreach ($arrayFromExcel as $rowFromExcel) {

        // Создание родительского элемента 2
        $root2 = $dom->createElement('set');

        // Создание родительского элемента 3
        $root3 = $dom->createElement('items');

        // Дополнительные переменные
        $cellIsFirst = true; // Действие для айдишника
        $cellIsSecond = true; // Действие для пометки barcode как primary

        foreach ($rowFromExcel as $cell) {

            if ($cellIsFirst) {
                // Привязывание атрибута к родительскому элементу 2 из первого столбца экселя

                $root2 = $dom->createElement('set');

                $attrRoot2SetId = new DOMAttr('id', $cell);
                $root2->setAttributeNode($attrRoot2SetId);

                $cellIsFirst = false;

                continue;
            }

            $barcode = $dom->createElement('barcode', $cell); // Создает элемент с баркодом


            if ($cellIsSecond) {
                // Привязывание атрибута к первому баркоду (второй столбец экселя)
                $attrPrimary = new DOMAttr('primary', 'true');
                $barcode->setAttributeNode($attrPrimary);

                $cellIsSecond = false;
            }

            $root3->appendChild($barcode);
        }

        // Вложение родительских элементов
        $root2->appendChild($root3);
        $root1->appendChild($root2);

    }

    // Вложение верхнего родительского элемента. В итоге порядок: 1 - самый верхний, 2-ой вложен в 1-ый, 3-ие во 2-ой
    $dom->appendChild($root1);

    // Сохранение файла во временную папку
    $dom->save($localXmlPath);
}


/**
 * Отправляет файл на FTP сервер (перезаписывает, если с таким именем уже существует на FTP)
 *
 * @param string $newFileName Этим именем будет называться файл залитый на ftp
 * @param string $localFilePath Путь к существующему файлу для отправки
 *
 * @return void
 *
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function uploadToFtp(string $newFileName, string $localFilePath)
{
    $ftp = connectToFtp();

    if (!ftp_put($ftp, $newFileName, $localFilePath, FTP_ASCII)) { // загрузка файла
        throw new Exception("Не удалось загрузить $newFileName на сервер");
    }

    ftp_close($ftp);

    logMsg("Успешно залили файл $localFilePath на фтп под именем: $newFileName");
}


/**
 * Устанавливает соединение с FTP-сервером. Убеждается в успешной логинизации
 *
 * @return resource
 *
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function connectToFtp()
{
    $ftp = ftp_connect($_ENV['FTP_SERVER']); // установка соединения

    if (!$ftp) {
        throw new Exception("FTP ошибка: Не Удалось подсоединиться к серверу");
    }

    if (!ftp_login($ftp, $_ENV['FTP_USER'], $_ENV['FTP_PASSWORD'])) {
// До этой строки дойти не должно, т.к. прежде должен выброситься Warning, а он должен выбросить ErrorException (мы переделали)
        throw new Exception("FTP ошибка: Неверный логин / пароль");
    }

    ftp_pasv($ftp, true);

    return $ftp;
}


/**
 * Логирует старт работы. Пишет в лог все что прислали из основной формы
 *
 * @return void
 */
function logStartMain(): void
{
    $string = str_repeat("-", 50) . PHP_EOL . "Были присланы данные:";

    foreach ($_FILES as $key => $sentFile) {
        $string = $string . PHP_EOL . $key . " ||| название файла: " . $sentFile["name"] . " ||| Размер: " . $sentFile[FILES_SIZE_KEY];
    }

    logMsg($string);
}


/**
 * Логирует сообщение
 *
 * @param string $logString Строка для логирования
 *
 * @return void
 */
function logMsg(string $logString): void
{
    $logFolder = LOG_FOLDER_ROOT . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');

    if (!is_dir($logFolder)) { // Проверяет создана ли соответствующая папка. Создает, если не существует
        mkdir($logFolder, 0777, true);
    }

    $logFileAddress = $logFolder . DIRECTORY_SEPARATOR . date('d') . '.log';

    $logString = date('H-i-s') . ": " . $logString . PHP_EOL;
    file_put_contents($logFileAddress, $logString, FILE_APPEND);
}

include 'templates/upload-form.php'; // Html Форма