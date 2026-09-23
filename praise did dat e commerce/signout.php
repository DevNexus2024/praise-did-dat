<?php
declare(strict_types=1);

session_start();
unset($_SESSION['pdd_customer_id'], $_SESSION['pdd_customer_name'], $_SESSION['pdd_customer_email'], $_SESSION['pdd_cart'], $_SESSION['pdd_direct_order']);
session_regenerate_id(true);
header('Location: index.html', true, 303);
exit;
