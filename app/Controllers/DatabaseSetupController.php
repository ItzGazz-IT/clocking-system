<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\DatabaseSetup;
use RuntimeException;
use Throwable;

final class DatabaseSetupController extends Controller
{
    public function show(Request $request): Response
    {
        if (!$this->available()) {
            return Response::redirect(app_url('/login'));
        }

        if (!self::needsDatabase() && self::authorized()) {
            return Response::redirect(app_url('/setup'));
        }

        return $this->view('setup/database', [
            'enabled' => strlen(self::token()) >= 32,
            'authorized' => self::authorized(),
            'connected' => DatabaseSetup::provisioned(),
            'error' => Session::pull('_database_setup_error'),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->available()) {
            return Response::redirect(app_url('/login'));
        }

        try {
            $input = $request->body();
            $action = $input['action'] ?? '';
            if ($action === 'authorize') {
                $submitted = $input['setup_token'] ?? '';
                if (strlen(self::token()) < 32 || !is_string($submitted) || !hash_equals(self::token(), $submitted)) {
                    throw new RuntimeException('The installer access key is invalid.');
                }
                session_regenerate_id(true);
                Session::put('_database_setup_key', hash('sha256', self::token()));
            } elseif (self::authorized()) {
                if ($action === 'connect') {
                    DatabaseSetup::connect($input);
                } elseif ($action === 'initialize') {
                    DatabaseSetup::initialize();
                    return Response::redirect(app_url('/setup'));
                }
            }
        } catch (Throwable $exception) {
            $message = $exception::class === RuntimeException::class
                ? $exception->getMessage()
                : 'Database setup failed. Check access, permissions, and database compatibility.';
            Session::put('_database_setup_error', $message);
        }

        return Response::redirect(app_url('/setup/database'));
    }

    public static function authorized(): bool
    {
        $token = self::token();
        return strlen($token) >= 32 && hash_equals(hash('sha256', $token), (string) Session::get('_database_setup_key', ''));
    }

    private static function token(): string
    {
        return (string) env('SETUP_TOKEN', '');
    }

    public static function needsDatabase(): bool
    {
        if (DatabaseSetup::provisioned()) {
            return !DatabaseSetup::initialized(Database::connection());
        }

        return (string) env('DB_DATABASE', '') === '';
    }

    private function available(): bool
    {
        if (self::needsDatabase()) {
            return true;
        }
        if (!DatabaseSetup::provisioned()) {
            return false;
        }

        $pdo = Database::connection();
        return (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    }
}