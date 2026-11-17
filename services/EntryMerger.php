<?php

namespace YesWiki\Importer\Service;

// Three-way merge of an imported entry: local and remote values against what the last import received and stored.
class EntryMerger
{
    public const POLICY_LOCAL = 'local';
    public const POLICY_REMOTE = 'remote';

    // A field value normalized so remote json and local values compare equal.
    public static function comparable($value): string
    {
        if (is_array($value)) {
            $value = implode(',', array_map(function ($item) {
                return is_scalar($item) ? (string) $item : json_encode($item);
            }, array_values($value)));
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '';
        } elseif ($value === null) {
            $value = '';
        } elseif (!is_scalar($value)) {
            $value = json_encode($value);
        }
        return trim((string) $value);
    }

    public static function hash($value): string
    {
        return sha1(self::comparable($value));
    }

    // The base after an import: per field, the hash of the remote value received and of the value stored here.
    public static function base(array $remote, array $stored, array $passKeys = []): array
    {
        $base = [];
        foreach ($remote as $key => $value) {
            if (!in_array($key, $passKeys, true)) {
                $base[$key] = ['r' => self::hash($value), 'l' => self::hash($stored[$key] ?? null)];
            }
        }
        return $base;
    }

    // Merges $remote into $local field by field against $base; $passKeys always take the remote value.
    public static function merge(array $local, array $remote, array $base, string $policy, array $passKeys = []): array
    {
        $values = [];
        $newBase = [];
        $taken = [];
        $conflicts = [];
        foreach ($remote as $key => $remoteValue) {
            if (in_array($key, $passKeys, true)) {
                $values[$key] = $remoteValue;
                continue;
            }
            $localValue = $local[$key] ?? null;
            $remoteHash = self::hash($remoteValue);
            $localHash = self::hash($localValue);
            $fieldBase = $base[$key] ?? null;
            $remoteChanged = $fieldBase === null || $remoteHash !== $fieldBase['r'];
            $localChanged = $fieldBase !== null && $localHash !== $fieldBase['l'];
            if (!$remoteChanged) {
                $values[$key] = $localValue;
                $newBase[$key] = $fieldBase;
            } elseif (!$localChanged || $remoteHash === $localHash) {
                $values[$key] = $remoteValue;
                $newBase[$key] = ['r' => $remoteHash, 'l' => $localHash];
                $taken[] = $key;
            } elseif ($policy === self::POLICY_REMOTE) {
                $values[$key] = $remoteValue;
                $newBase[$key] = ['r' => $remoteHash, 'l' => $localHash];
                $taken[] = $key;
                $conflicts[$key] = ['local' => $localValue, 'remote' => $remoteValue];
            } else {
                $values[$key] = $localValue;
                $newBase[$key] = ['r' => $remoteHash, 'l' => $fieldBase['l']];
                $conflicts[$key] = ['local' => $localValue, 'remote' => $remoteValue];
            }
        }
        return ['values' => $values, 'base' => $newBase, 'taken' => $taken, 'conflicts' => $conflicts];
    }

    // Records, for the fields the merge took from the remote, the value the local wiki actually stored.
    public static function storedInto(array $base, array $stored, array $taken): array
    {
        foreach ($taken as $key) {
            if (isset($base[$key])) {
                $base[$key]['l'] = self::hash($stored[$key] ?? null);
            }
        }
        return $base;
    }
}
