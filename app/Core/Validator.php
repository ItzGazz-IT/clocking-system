<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @return array<string, string> */
    public static function login(array $input): array
    {
        $errors = [];
        $username = trim((string) ($input['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]{2,80}$/', $username)) {
            $errors['username'] = 'Enter your username.';
        }
        if (!is_string($input['password'] ?? null) || ($input['password'] ?? '') === '') {
            $errors['password'] = 'Enter your password.';
        }
        return $errors;
    }

    /** @return array<string, string> */
    public static function department(array $input): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_-]{2,40}$/', trim((string) ($input['code'] ?? '')))) {
            $errors['code'] = 'Use 2–40 letters, numbers, dashes or underscores.';
        }
        if (mb_strlen(trim((string) ($input['name'] ?? ''))) < 2) {
            $errors['name'] = 'Department name must contain at least 2 characters.';
        }
        return $errors;
    }

    /** @return array<string, string> */
    public static function shift(array $input): array
    {
        $errors = self::department($input);
        foreach (['start_time', 'end_time'] as $field) {
            if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', trim((string) ($input[$field] ?? '')))) {
                $errors[$field] = 'Enter a valid time.';
            }
        }
        foreach (['paid_minutes', 'grace_period_minutes', 'expected_lunch_minutes'] as $field) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1440) {
                $errors[$field] = 'Enter minutes between 0 and 1440.';
            }
        }
        return $errors;
    }

    /** @return array<string, string> */
    public static function employee(array $input): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', trim((string) ($input['employee_number'] ?? '')))) {
            $errors['employee_number'] = 'Use 2–50 letters, numbers, dashes or underscores.';
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (mb_strlen(trim((string) ($input[$field] ?? ''))) < 2) {
                $errors[$field] = 'Enter at least 2 characters.';
            }
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        foreach (['department_id'] as $field) {
            if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) === false) {
                $errors[$field] = 'Choose a valid option.';
            }
        }
        $mode = (string) ($input['attendance_mode'] ?? 'flexible');
        if ($mode !== 'flexible') {
            $errors['attendance_mode'] = 'Employees use automatic flexible attendance.';
        }
        if ($mode === 'fixed' && filter_var($input['shift_id'] ?? null, FILTER_VALIDATE_INT) === false) {
            $errors['shift_id'] = 'Choose a valid shift.';
        }
        if ($mode === 'flexible') {
            $effective = (string) ($input['activity_attendance_effective_from'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
                $errors['activity_attendance_effective_from'] = 'Choose the date activity attendance starts.';
            }
            foreach (['daily_target_minutes' => [1, 1440], 'idle_threshold_minutes' => [1, 240], 'offline_timeout_minutes' => [1, 240]] as $field => [$min, $max]) {
                $defaults = ['daily_target_minutes' => 480, 'idle_threshold_minutes' => 30, 'offline_timeout_minutes' => 15];
                $value = filter_var($input[$field] ?? $defaults[$field], FILTER_VALIDATE_INT);
                if ($value === false || $value < $min || $value > $max) {
                    $errors[$field] = "Enter minutes between {$min} and {$max}.";
                }
            }
        }
        return $errors;
    }

    /** @return array<string, string> */
    public static function passwordChange(array $input): array
    {
        $errors = [];
        if (!is_string($input['current_password'] ?? null) || $input['current_password'] === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 12
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors['password'] = 'Use at least 12 characters with upper, lower, number and symbol.';
        }
        if ($password !== (string) ($input['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        return $errors;
    }
}
