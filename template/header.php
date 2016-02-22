<?php
    function template_header($page_title) {
        if (!$page_title) {
            trigger_error('template_header() called without $page_title', E_USER_ERROR);
        }

        $page_title = htmlspecialchars($page_title);
?>
<html>
<head>
    <title><?=$page_title?></title>
</head>

<body>

<h1><?=$page_title?></h1>

<?php
    }
?>