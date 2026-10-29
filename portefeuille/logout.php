<?php
require_once __DIR__ . '/bootstrap.php';
Auth::deconnecter();
header('Location: index.php');
exit;
