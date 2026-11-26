<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Yaml;

class SubscribeDisplayService
{
    public static function matchRule(Request $request): array
    {
        $rules = json_decode((string) config('v2board.subscribe_display_rules'), true) ?: [];
        $email = (string) $request->user->email;
        $ua = (string) $request->userAgent();
        foreach ($rules as $rule) {
            $value = $rule['value'] ?? '';
            switch ($rule['match']) {
                case 'all': $matched = true; break;
                case 'email_exact': $matched = strcasecmp($email, $value) === 0; break;
                case 'email_suffix': $matched = strcasecmp(substr($email, -strlen($value)), $value) === 0; break;
                case 'email_keyword': $matched = stripos($email, $value) !== false; break;
                case 'ua_contains': $matched = stripos($ua, $value) !== false; break;
                case 'ua_not_contains': $matched = $ua !== '' && stripos($ua, $value) === false; break;
                default: $matched = false;
            }
            if ($matched) {
                return $rule;
            }
        }
        return [];
    }

    public static function renameNodes(array &$servers, array $rule): void
    {
        foreach ($servers as &$server) {
            $server['name'] = self::rename($server['name'], $rule);
        }
    }

    public static function rewriteResponse($response, array $rule)
    {
        if (!$rule || ($response instanceof Response && $response->getStatusCode() !== 200)) {
            return $response;
        }
        $body = $response instanceof Response ? $response->getContent() : $response;
        if (!is_string($body) || $body === '') {
            return $response;
        }
        if (!empty($rule['replacements'])) {
            $body = self::rewriteGroups($body, $rule);
        }
        $response = $response instanceof Response ? $response : response($body);
        $response->setContent($body);
        $response->headers->remove('Content-Length');
        if (!empty($rule['title'])) {
            if (!headers_sent()) {
                header_remove('Content-Disposition');
            }
            $response->headers->set('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($rule['title']));
            $response->headers->set('profile-title', 'base64:' . base64_encode($rule['title']));
        }
        return $response;
    }

    private static function rename(string $name, array $rule): string
    {
        return strtr($name, $rule['replacements'] ?? []);
    }

    private static function rewriteGroups(string $body, array $rule): string
    {
        $json = json_decode($body);
        if (isset($json->outbounds) && is_array($json->outbounds)) {
            $groups = [];
            foreach ($json->outbounds as $outbound) {
                if (in_array($outbound->type ?? '', ['selector', 'urltest'], true) && isset($outbound->tag)) {
                    $groups[$outbound->tag] = self::rename($outbound->tag, $rule);
                }
            }
            self::rewriteReferences($json, $groups, ['tag', 'outbound', 'outbounds', 'default', 'final', 'detour', 'download_detour']);
            return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $body;
        }

        if (!preg_match('/^proxy-groups:/m', $body)) {
            return $body;
        }
        try {
            $yaml = Yaml::parse($body);
            if (!is_array($yaml['proxy-groups'] ?? null)) {
                return $body;
            }
            $groups = [];
            foreach ($yaml['proxy-groups'] as &$group) {
                if (isset($group['name'])) {
                    $oldName = $group['name'];
                    $group['name'] = self::rename($oldName, $rule);
                    $groups[$oldName] = $group['name'];
                }
            }
            unset($group);
            self::rewriteReferences($yaml['proxy-groups'], $groups, ['proxies']);
            foreach ($yaml['rules'] ?? [] as $index => $line) {
                if (!is_string($line)) {
                    continue;
                }
                $parts = explode(',', $line);
                $target = count($parts) - 1;
                if (strtolower(trim($parts[$target])) === 'no-resolve') {
                    --$target;
                }
                if ($target >= 1 && isset($groups[trim($parts[$target])])) {
                    $parts[$target] = $groups[trim($parts[$target])];
                    $yaml['rules'][$index] = implode(',', $parts);
                }
            }
            return Yaml::dump($yaml, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        } catch (ExceptionInterface $e) {
            return $body;
        }
    }

    private static function rewriteReferences(&$data, array $names, array $keys): void
    {
        foreach ($data as $key => &$value) {
            if (in_array($key, $keys, true)) {
                if (is_string($value)) {
                    $value = $names[$value] ?? $value;
                } elseif (is_array($value) && array_values($value) === $value && count(array_filter($value, 'is_string')) === count($value)) {
                    $value = array_map(function ($name) use ($names) {
                        return $names[$name] ?? $name;
                    }, $value);
                }
            }
            if (is_array($value) || is_object($value)) {
                self::rewriteReferences($value, $names, $keys);
            }
        }
    }
}
