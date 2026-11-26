<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

use App\Audit\Actor;
use App\Database\Backup\PreMigrateBackups;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The general settings of the operator (task 8b) over the store of task 8
 * (Settings): what is in force for each, who may change it, and the one way
 * to change it that the page and the console share.
 *
 * What is in force, strongest first: an environment variable (an emergency
 * override, the field is then locked), the value the operator stored, the
 * default. A value that does not pass the rules of its setting is not in
 * force wherever it comes from; the next source is.
 *
 * Nothing is kept between calls: every read goes to the configuration and the
 * database, so a worker never serves a value an earlier request saw.
 */
final class SettingsRegistry
{
    /** Request attribute that holds the stored values read in this request. */
    private const MEMO = 'tesserarr.settings.stored';

    public function __construct(
        private readonly Settings $settings,
        private readonly EnvOverride $env,
        private readonly ConnectionInterface $db,
    ) {}

    public function value(SettingKey $key): string|int
    {
        return $this->resolve($key)->value;
    }

    public function resolve(SettingKey $key): ResolvedSetting
    {
        return $this->resolveMany([$key])[$key->value];
    }

    /**
     * Every general setting, in the order of SettingKey, with one query.
     *
     * @return array<string, ResolvedSetting> by key
     */
    public function resolveAll(): array
    {
        return $this->resolveMany(SettingKey::cases());
    }

    /**
     * @param  list<SettingKey>  $keys
     * @return array<string, ResolvedSetting> by key
     */
    public function resolveMany(array $keys): array
    {
        $stored = $this->storedValues();

        $resolved = [];
        foreach ($keys as $key) {
            $resolved[$key->value] = $this->resolveOne($key, $stored[$key->value] ?? null);
        }

        return $resolved;
    }

    /**
     * How many backups tesserarr:migrate keeps. It runs before the migrations,
     * so on a first installation there is no settings table yet; the default
     * holds then. An environment value that is not a whole number from 1 to 50
     * stops the command (it is the operator's explicit emergency override, not
     * something to guess around).
     *
     * @throws \InvalidArgumentException
     */
    public function backupKeep(): int
    {
        $override = $this->env->raw(SettingKey::BackupsKeep);
        if ($override !== null) {
            return PreMigrateBackups::keep($override);
        }

        if (! Schema::hasTable('app_settings')) {
            return PreMigrateBackups::KEEP;
        }

        return (int) $this->value(SettingKey::BackupsKeep);
    }

    /**
     * Changes settings. $input maps a field name (SettingKey::field()) to the
     * value to give it; a name that is no general setting is ignored (the
     * form of a newer or older page, never a way to reach another key).
     *
     * Everything or nothing: a field that an environment variable overrides,
     * or a value that fails its rules, refuses the whole change. A value equal
     * to the one in force is not written, so a save of an unchanged form
     * leaves no entry in the audit log. Each key that changes is one entry,
     * with the key and never the value (Settings::set).
     *
     * @param  array<string, mixed>  $input
     * @return list<SettingKey> the settings that changed
     *
     * @throws ValidationException
     */
    public function update(array $input, Actor $actor): array
    {
        // What was read earlier in this request is not what is stored now.
        $this->forgetStored();

        $wanted = [];
        foreach ($input as $field => $raw) {
            $key = SettingKey::fromField((string) $field);
            if ($key !== null) {
                $wanted[$key->field()] = [$key, $raw];
            }
        }

        $errors = [];
        /** @var list<array{SettingKey, string|int}> $valid */
        $valid = [];
        $inForce = $this->resolveMany(array_map(fn (array $pair) => $pair[0], array_values($wanted)));
        foreach ($wanted as $field => [$key, $raw]) {
            if ($inForce[$key->value]->overridden()) {
                $errors[$field] = __('settings.overridden', ['env' => $key->env()]);

                continue;
            }
            $checked = $this->check($key, $raw);
            if ($checked instanceof ValidationException) {
                $errors[$field] = (string) ($checked->errors()[$field][0] ?? $checked->getMessage());

                continue;
            }
            $valid[] = [$key, $checked];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changed = [];
        $this->db->transaction(function () use ($valid, $inForce, $actor, &$changed): void {
            foreach ($valid as [$key, $value]) {
                $current = $inForce[$key->value] ?? null;
                // Compared with what is stored: a stored value that is invalid
                // (so the default is in force) is overwritten by saving that
                // default; nothing stored and the same value is not a change.
                if ($value !== $current?->value || $current->storedInvalid) {
                    $this->settings->set($key->value, $value, $actor);
                    $changed[] = $key;
                }
            }
        });

        $this->forgetStored();

        return $changed;
    }

    private function resolveOne(SettingKey $key, mixed $stored): ResolvedSetting
    {
        $override = $this->env->raw($key);
        $envInvalid = false;
        if ($override !== null) {
            $checked = $this->check($key, $override);
            if (! $checked instanceof ValidationException) {
                return new ResolvedSetting($key, $checked, SettingSource::Environment);
            }
            $envInvalid = true;
        }

        $storedInvalid = false;
        if ($stored !== null) {
            $checked = $this->check($key, $stored);
            if (! $checked instanceof ValidationException) {
                return new ResolvedSetting($key, $checked, SettingSource::Stored, $envInvalid);
            }
            $storedInvalid = true;
        }

        return new ResolvedSetting($key, $key->default(), SettingSource::Default, $envInvalid, $storedInvalid);
    }

    /**
     * The stored values of all general settings, read once per request: kept
     * on the request object (Octane gives every request its own, so nothing
     * outlives it), never in a static. Middleware, the page and the controller
     * of one request share one read.
     *
     * @return array<string, mixed>
     */
    private function storedValues(): array
    {
        $attributes = app('request')->attributes;
        $memo = $attributes->get(self::MEMO);
        if (is_array($memo)) {
            return $memo;
        }

        $values = $this->settings->many(array_map(fn (SettingKey $key) => $key->value, SettingKey::cases()));
        $attributes->set(self::MEMO, $values);

        return $values;
    }

    private function forgetStored(): void
    {
        app('request')->attributes->remove(self::MEMO);
    }

    /** The value as it is stored, or the exception that names what is wrong with it. */
    private function check(SettingKey $key, mixed $raw): string|int|ValidationException
    {
        $field = $key->field();
        $value = $key->prepare($raw);

        try {
            Validator::make([$field => $value], [$field => $key->rules()])->validate();
        } catch (ValidationException $invalid) {
            return $invalid;
        }

        return $key->cast($value);
    }
}
