<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\user;

use mindstellar\database\Db;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\location\LocationQuery;
use mindstellar\location\LocationService;

/**
 * A shopper's delivery addresses, at most MAX. The default one is the account's own address
 * columns, so the profile and old checkout prefill read it as before; its recipient name and label
 * are kept in the store's row, and the account name stands in when no name was saved. Making
 * another address the default swaps the two. Each change locks the user's row. The rows have no
 * user column, so the book is exported on `personal_data_export` and removed on `delete_user` here.
 */
final class AddressBook
{
    /** Most addresses one account keeps, the default among them. */
    public const MAX = 10;

    /** Each field and its longest length; they fit the account's address columns. label may be empty. */
    public const FIELDS = array(
        'label'    => 40,
        'name'     => 100,
        'phone'    => 40,
        'line1'    => 100,
        'city'     => 100,
        'state'    => 100,
        'postcode' => 15,
        'country'  => 2,
    );

    /** The default's id when it was never given one: an address typed on the profile page. */
    public const MAIN_ID = 'main';

    /** What an address id looks like. */
    public const ID_PATTERN = '^[a-z0-9]{1,16}$';

    private AddressStore $store;

    public function __construct(?AddressStore $store = null)
    {
        $this->store = $store ?? new AddressStore();
    }

    /**
     * Every address, the default first, each with `default`.
     *
     * @return array<int,array<string,string|bool>>
     * @throws \mindstellar\database\DbException
     */
    public function all(int $userId): array
    {
        if ($userId <= 0) {
            return array();
        }
        $row  = $this->store->read($userId);
        $main = $this->main($userId, $row['default']);
        $out  = $main === null ? array() : array($main + array('default' => true));
        foreach (self::cleanList($row['list']) as $address) {
            $out[] = $address + array('default' => false);
        }

        return $out;
    }

    /**
     * One of the user's addresses, or null.
     *
     * @return array<string,string|bool>|null
     * @throws \mindstellar\database\DbException
     */
    public function find(int $userId, string $id): ?array
    {
        foreach ($this->all($userId) as $address) {
            if ($address['id'] === $id) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Add an address. The first one, or one sent with $default, becomes the default.
     *
     * @param array<string,mixed> $input FIELDS keys
     *
     * @return array<string,string|bool> the address as saved
     * @throws InvalidException at `/<field>`, or `/` too_many when the book is full
     * @throws \mindstellar\database\DbException
     */
    public function add(int $userId, array $input, bool $default = false): array
    {
        $address = self::check($input) + array('id' => self::newId());

        return $this->change($userId, function (?array $main, array $list) use ($address, $default): array {
            if (($main === null ? 0 : 1) + count($list) >= self::MAX) {
                throw new InvalidException('/', 'too_many', sprintf(_m('You can keep up to %d addresses. Delete one first.'), self::MAX));
            }
            if ($main === null) {
                return array($address, $list, $address['id']);
            }
            if ($default) {
                return array($address, array_merge(array($this->demote($main)), $list), $address['id']);
            }
            $list[] = $address;

            return array(null, $list, $address['id']);
        });
    }

    /**
     * Replace one address's fields; it keeps its id and whether it is the default.
     *
     * @param array<string,mixed> $input FIELDS keys
     *
     * @return array<string,string|bool>
     * @throws NotFoundException when the user has no address with that id
     * @throws InvalidException at `/<field>`
     * @throws \mindstellar\database\DbException
     */
    public function update(int $userId, string $id, array $input): array
    {
        $address = array('id' => $id) + self::check($input);

        return $this->change($userId, static function (?array $main, array $list) use ($address, $id): array {
            if ($main !== null && $main['id'] === $id) {
                return array($address, $list, $id);
            }
            foreach ($list as $n => $entry) {
                if ($entry['id'] === $id) {
                    $list[$n] = $address;

                    return array(null, $list, $id);
                }
            }

            throw new NotFoundException(_m('That address was not found.'));
        });
    }

    /**
     * Delete an address. Deleting the default makes the next one the default; with none left the
     * account's street and postcode are cleared, and its place and phone stay.
     *
     * @throws NotFoundException
     * @throws \mindstellar\database\DbException
     */
    public function remove(int $userId, string $id): void
    {
        $this->change($userId, function (?array $main, array $list) use ($userId, $id): array {
            if ($main !== null && $main['id'] === $id) {
                if ($list === array()) {
                    UserStore::setAddress($userId, array('s_address' => null, 's_zip' => null));

                    return array(false, array(), '');
                }
                $next = array_shift($list);

                return array($next, $list, '');
            }
            $kept = array_values(array_filter($list, static fn (array $entry): bool => $entry['id'] !== $id));
            if (count($kept) === count($list)) {
                throw new NotFoundException(_m('That address was not found.'));
            }

            return array(null, $kept, '');
        });
    }

    /**
     * Make an address the default: it is written to the account's address columns and the old
     * default moves into the list.
     *
     * @throws NotFoundException
     * @throws \mindstellar\database\DbException
     */
    public function makeDefault(int $userId, string $id): void
    {
        $this->change($userId, function (?array $main, array $list) use ($id): array {
            if ($main !== null && $main['id'] === $id) {
                return array(null, $list, $id);
            }
            foreach ($list as $n => $entry) {
                if ($entry['id'] === $id) {
                    unset($list[$n]);
                    $rest = array_values($list);

                    return array($entry, $main === null ? $rest : array_merge(array($this->demote($main)), $rest), $id);
                }
            }

            throw new NotFoundException(_m('That address was not found.'));
        });
    }

    /**
     * Keep an address typed at checkout, unless the book already has it or is full. The first one
     * becomes the default.
     *
     * @param array<string,mixed> $input FIELDS keys, already passed check()
     *
     * @throws \mindstellar\database\DbException
     */
    public function keep(int $userId, array $input): void
    {
        $address = self::check($input);
        $same    = self::fingerprint($address);
        $all     = $this->all($userId);
        if (count($all) >= self::MAX) {
            return;
        }
        foreach ($all as $entry) {
            if (self::fingerprint($entry) === $same) {
                return;
            }
        }
        try {
            $this->add($userId, $address);
        } catch (InvalidException $e) {
            // Filled up meanwhile.
        }
    }

    /**
     * The fields cleaned and checked: every field but label is needed, the country is one of the
     * site's, a mobile has at least 8 digits and an Indian PIN code has 6.
     *
     * @param array<string,mixed> $input
     * @param string              $at    the pointer the fields sit under, e.g. `/address`
     *
     * @return array<string,string> FIELDS keys
     * @throws InvalidException at `$at/<field>`
     */
    public static function check(array $input, string $at = ''): array
    {
        $out    = array();
        $errors = array();
        foreach (self::FIELDS as $field => $max) {
            $value = $input[$field] ?? '';
            $value = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '') : '';
            if ($field === 'country') {
                $value = strtoupper($value);
            }
            $out[$field] = $value;
            $error       = null;
            if ($value === '' && $field !== 'label') {
                $error = array('required', _m('This field is needed.'));
            } elseif (mb_strlen($value, 'UTF-8') > $max) {
                $error = array('maxLength', sprintf(_m('Keep this under %d characters.'), $max + 1));
            } elseif ($field === 'phone' && strlen((string) preg_replace('/\D+/', '', $value)) < 8) {
                $error = array('pattern', _m('Enter a mobile number with at least 8 digits.'));
            } elseif ($field === 'country' && (new LocationQuery())->lineage($value, 0, 0)['country'] === null) {
                $error = array('unknown', _m('Choose a country from the list.'));
            }
            if ($error !== null) {
                $errors[] = array('pointer' => $at . '/' . $field, 'code' => $error[0], 'message' => $error[1]);
            }
        }
        if ($out['country'] === 'IN' && $out['postcode'] !== '' && preg_match('/^\d{6}$/', $out['postcode']) !== 1) {
            $errors[] = array('pointer' => $at . '/postcode', 'code' => 'pattern', 'message' => _m('PIN code needs 6 digits.'));
        }
        if ($errors !== array()) {
            throw InvalidException::all($errors);
        }

        return $out;
    }

    /**
     * An address in the shape an order keeps.
     *
     * @param array<string,mixed> $address
     *
     * @return array<string,string> GoodsCheckout::ADDRESS keys
     */
    public static function forOrder(array $address): array
    {
        $out = array();
        foreach (array('name', 'phone', 'line1', 'line2', 'city', 'state', 'postcode', 'country') as $field) {
            $out[$field] = (string) ($address[$field] ?? '');
        }

        return $out;
    }

    /**
     * `personal_data_export`: the address book beside the account's tables.
     *
     * @param mixed $out
     * @param mixed $userId
     *
     * @return mixed
     */
    public static function export($out, $userId = 0)
    {
        if (is_array($out)) {
            $out['data']['addresses'] = array(
                'retention' => \mindstellar\privacy\PersonalData::ERASE_DELETED,
                'why'       => 'The delivery addresses they saved; the default one is also the account\'s own address.',
                'rows'      => (new self())->all((int) $userId),
            );
        }

        return $out;
    }

    /**
     * `delete_user`: the address book goes with the account.
     *
     * @param mixed $userId
     */
    public static function erase($userId): void
    {
        (new AddressStore())->delete((int) $userId);
    }

    /**
     * Read, change and write the book with the user's row locked. $edit gets the default (or
     * null) and the other addresses, and answers the new default
     * (null: unchanged, false: none), the new list and the id of the address to answer with.
     *
     * @param callable(array<string,string>|null,array<int,array<string,string>>):array{0:array<string,string>|null|false,1:array<int,array<string,string>>,2:string} $edit
     *
     * @return array<string,string|bool> the address $edit named, or none
     * @throws NotFoundException for no user, or from $edit
     * @throws \mindstellar\database\DbException
     */
    private function change(int $userId, callable $edit): array
    {
        if ($userId <= 0) {
            throw new NotFoundException(_m('That address was not found.'));
        }
        $answer = Db::transaction(function () use ($userId, $edit): string {
            UserStore::lock($userId);
            $row  = $this->store->read($userId);
            $meta = is_array($row['default']) ? $row['default'] : array();
            $main = $this->main($userId, $row['default']);
            [$next, $list, $answer] = $edit($main, self::cleanList($row['list']));
            if ($next === false) {
                $meta = null;
            } elseif (is_array($next)) {
                $this->writeMain($userId, $next);
                $meta = array('id' => $next['id'], 'label' => $next['label'], 'name' => $next['name']);
            } elseif ($main === null) {
                $meta = null;
            }
            $this->store->save($userId, $meta === array() ? null : $meta, $list);

            return $answer;
        });

        return $answer === '' ? array() : (array) $this->find($userId, $answer);
    }

    /**
     * The default address, read from the account; none while the account has no street.
     *
     * @param array<string,mixed>|null $meta the store's default record
     *
     * @return array<string,string>|null
     * @throws \mindstellar\database\DbException
     */
    private function main(int $userId, ?array $meta): ?array
    {
        $user = UserStore::find($userId);
        if ($user === null || trim((string) ($user['s_address'] ?? '')) === '') {
            return null;
        }
        $id   = (string) ($meta['id'] ?? '');
        $name = trim((string) ($meta['name'] ?? ''));

        return array(
            'id'       => preg_match('/' . self::ID_PATTERN . '/', $id) === 1 ? $id : self::MAIN_ID,
            'label'    => (string) ($meta['label'] ?? ''),
            'name'     => $name !== '' ? $name : (string) ($user['s_name'] ?? ''),
            'phone'    => (string) ($user['s_phone_mobile'] ?? ''),
            'line1'    => (string) $user['s_address'],
            'city'     => (string) ($user['s_city'] ?? ''),
            'state'    => (string) ($user['s_region'] ?? ''),
            'postcode' => (string) ($user['s_zip'] ?? ''),
            'country'  => strtoupper((string) ($user['fk_c_country_code'] ?? '')),
        );
    }

    /**
     * Write an address to the account's address columns, matching the region and city by name.
     *
     * @param array<string,string> $address
     *
     * @throws \mindstellar\database\DbException
     */
    private function writeMain(int $userId, array $address): void
    {
        $place = LocationService::resolve(array('countryCode' => $address['country'], 'region' => $address['state'], 'city' => $address['city']), true);
        UserStore::setAddress($userId, array(
            's_address'         => $address['line1'],
            's_zip'             => $address['postcode'],
            'fk_c_country_code' => $place['countryId'],
            's_country'         => $place['countryName'],
            'fk_i_region_id'    => $place['regionId'],
            's_region'          => $place['regionId'] !== null ? $place['regionName'] : $address['state'],
            'fk_i_city_id'      => $place['cityId'],
            's_city'            => $place['cityId'] !== null ? $place['cityName'] : $address['city'],
            'fk_i_city_area_id' => null,
            's_city_area'       => null,
            's_phone_mobile'    => $address['phone'],
        ));
    }

    /**
     * The old default as a list entry. It keeps its id unless it never had one of its own.
     *
     * @param array<string,string> $main
     *
     * @return array<string,string>
     */
    private function demote(array $main): array
    {
        if ($main['id'] === self::MAIN_ID) {
            $main['id'] = self::newId();
        }

        return $main;
    }

    /**
     * The stored list with every entry in FIELDS shape and a valid, unique id.
     *
     * @param array<int,mixed> $list
     *
     * @return array<int,array<string,string>>
     */
    private static function cleanList(array $list): array
    {
        $out  = array();
        $seen = array();
        foreach ($list as $entry) {
            $id = is_array($entry) ? (string) ($entry['id'] ?? '') : '';
            if ($id === '' || isset($seen[$id]) || preg_match('/' . self::ID_PATTERN . '/', $id) !== 1) {
                continue;
            }
            $seen[$id] = true;
            $address   = array('id' => $id);
            foreach (array_keys(self::FIELDS) as $field) {
                $address[$field] = is_scalar($entry[$field] ?? null) ? (string) $entry[$field] : '';
            }
            $out[] = $address;
            if (count($out) === self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $address
     */
    private static function fingerprint(array $address): string
    {
        $parts = array();
        foreach (array('name', 'phone', 'line1', 'city', 'state', 'postcode', 'country') as $field) {
            $parts[] = mb_strtolower(trim((string) ($address[$field] ?? '')), 'UTF-8');
        }

        return implode("\n", $parts);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
