<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

abstract class Model
{
    protected PDO $database;

    public function __construct()
    {
        $this->database = Database::connection();
    }
}

