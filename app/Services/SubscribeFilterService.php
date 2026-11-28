<?php

namespace App\Services;

class SubscribeFilterService
{
    /**
     * Apply domain rewrite rules based on UA
     */
    public static function applyDomainRewriteRules(array &$servers, string $ua): void
    {
        $rules = config('v2board.subscribe_domain_rewrite_rules', []);
        if (empty($rules) || empty($ua)) {
            return;
        }

        $matchedRules = [];
        foreach ($rules as $rule) {
            if (empty($rule['ua']) || empty($rule['domain']) || empty($rule['ip'])) {
                continue;
            }
            if (stripos($ua, $rule['ua']) !== false) {
                $matchedRules[] = $rule;
            }
        }
        if (empty($matchedRules)) {
            return;
        }

        $addressFields = ['host', 'server', 'address'];
        foreach ($servers as &$server) {
            foreach ($matchedRules as $rule) {
                foreach ($addressFields as $field) {
                    if (isset($server[$field]) && strtolower($server[$field]) === strtolower($rule['domain'])) {
                        $server[$field] = $rule['ip'];
                    }
                }
            }
        }
        unset($server);
    }

    /**
     * Apply node whitelist rules based on UA
     *
     * Security fix: properly handle missing node IDs to prevent restricted nodes from being delivered
     */
    public static function applyNodeWhitelistRules(array &$servers, string $ua): void
    {
        $rules = config('v2board.subscribe_node_whitelist_rules', []);
        if (empty($rules) || !is_array($rules)) {
            return;
        }

        // Build map: "type:id" => [allowed UA keyword, ...]
        $restricted = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $ruleUa = isset($rule['ua']) ? trim((string)$rule['ua']) : '';
            if ($ruleUa === '') {
                continue;
            }
            if (empty($rule['nodes']) || !is_array($rule['nodes'])) {
                continue;
            }
            foreach ($rule['nodes'] as $node) {
                if (!is_array($node) || empty($node['type']) || !isset($node['id'])) {
                    continue;
                }
                $key = $node['type'] . ':' . (int)$node['id'];
                $restricted[$key][] = $ruleUa;
            }
        }
        if (empty($restricted)) {
            return;
        }

        $servers = array_values(array_filter($servers, function ($server) use ($restricted, $ua) {
            // Security fix: if node data is missing 'id', treat it as unrestricted (default behavior)
            // This prevents construction of invalid keys like "v2ray:" that would bypass restrictions
            if (!isset($server['id'])) {
                return true;
            }

            $key = ($server['type'] ?? '') . ':' . (int)$server['id'];

            // Node is not restricted by any rule
            if (!isset($restricted[$key])) {
                return true;
            }

            // Node is restricted: check if UA matches any allowed pattern
            if (empty($ua)) {
                return false;
            }

            foreach ($restricted[$key] as $allowedUa) {
                if ($allowedUa === '') {
                    continue;
                }
                if (stripos($ua, $allowedUa) !== false) {
                    return true;
                }
            }

            // UA doesn't match any allowed pattern for this restricted node
            return false;
        }));
    }
}
