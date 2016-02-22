<?php

include_once('config.php');

include_once('module/database.php');

include_once('module/cache.php');

if (isset($_GET['item'])) {
    $item_id = intval($_GET['item']);
    $item = get_cache('item/' . $item_id, "SELECT * FROM item WHERE id = :item_id", array(':item_id' => $item_id));

    if (is_array($item) && count($item) == 1) {
        include_once('template/header.php');
        include_once('template/footer.php');

        include_once('template/item.php');

        $item = $item[0];

        template_header('Item ' . $item['id']);
        template_item($item);
        template_footer();
    }
} else {
    $items = get_cache("item_list/1", "SELECT * FROM item LIMIT 10");

    if (is_array($items) && count($items)) {
        include_once('template/header.php');
        include_once('template/footer.php');

        include_once('template/item_list.php');

        template_header('Item List');
        template_item_list($items);
        template_footer();
    }
}