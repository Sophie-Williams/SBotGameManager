<?php
namespace GameManager;

class Util
{
    public static function log($message,$file='log.txt')
    {
        file_put_contents($file, "\n".date('l jS \of F Y h:i:s A').": ".$message, FILE_APPEND | LOCK_EX);
    }
}