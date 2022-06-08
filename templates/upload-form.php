<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Excel2Xml</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="preloader" id="preloader"></div>

<div class="container">
    <?php
    if (!empty($alertMsg)) {
        require_once 'alert.php';
    }
    ?>
    <form action="" method="post" class="was-validated" enctype="multipart/form-data">
        <div class="form-group">
            <label for="pwd">Эксель:</label>
            <input type="file" class="form-control" id="<?= FILE_INPUT_EXCEL ?>" placeholder="Выберите файл"
                   name="<?= FILE_INPUT_EXCEL ?>">
        </div>
        <button id="my-listen-btn-submit" type="submit" name="<?= POST_SUBMIT ?>" class="btn btn-primary my-btn-listen">
            Отправить
        </button>
    </form>


    <script>

        $(document).ready(function () {
            $('.preloader').hide();
        })

        $('.my-listen-btn').on('click', function () {
            $('.preloader').show();
        })

        $('#my-listen-btn-submit').on('click', function () {
            if ($('#my-listen-invalid').css('display') === 'none')
            {
                $('.preloader').show();
            }
        })

    </script>
</body>
</html>