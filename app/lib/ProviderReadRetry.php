<?php
namespace app\lib;

/** Retry only explicit read operations; uncertain writes are never replayed. */
class ProviderReadRetry
{
    public static function run(callable $read)
    {
        for ($attempt = 0; ; $attempt++) {
            try { return $read(); }
            catch (\Exception $e) {
                $transient = in_array((int)$e->getCode(), [408,429,500,502,503,504], true)
                    || preg_match('/(?:throttl|rate exceeded|too many requests|service unavailable|request timeout|timed out|could not resolve|connection (?:reset|refused)|HTTP Code: (?:429|50[0234]))/i', $e->getMessage());
                if (!$transient || $attempt >= 2) throw $e;
                usleep(($attempt + 1) * 100000);
            }
        }
    }
}
