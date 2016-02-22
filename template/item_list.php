<?php
    function template_item_list($items) {
?>
    <ul>
<?php
        foreach($items as $item) {
?>
            <li>
                <?=nl2br(htmlspecialchars(trim($item['body'])))?>
            </li>
<?php
        }
?>
    </ul>
<?php
}
