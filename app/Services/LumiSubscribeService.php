<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Response;

class LumiSubscribeService
{
    public static function encryptResponse($response, $token)
    {
        if ($response instanceof Response && $response->getStatusCode() !== 200) {
            return $response;
        }

        $content = $response instanceof Response ? $response->getContent() : $response;
        if (!is_string($content) || $content === '') {
            return $response;
        }

        if (!is_string($token) || strlen($token) < 16) {
            abort(502, 'Lumi 订阅处理失败');
        }

        // Lumi uses the bytes from the subscription URL, including for temporary tokens.
        $key = substr($token, 0, 8) . substr($token, -8);
        $encrypted = openssl_encrypt($content, 'aes-128-ecb', $key);
        if ($encrypted === false) {
            abort(502, 'Lumi 订阅处理失败');
        }

        $response = $response instanceof Response ? $response : response($content);
        $response->setContent($encrypted);
        foreach (['Content-Encoding', 'Content-MD5', 'Content-Range', 'ETag', 'Last-Modified'] as $header) {
            $response->headers->remove($header);
        }
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('Content-Length', (string) strlen($encrypted));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
