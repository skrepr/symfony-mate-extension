<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Shared token resolution for tools that read a single profile: empty token =
 * most recent request (optionally via URL filter), with every failure path
 * returned as a ready-made JSON error message for the agent.
 *
 * Expects the using class to have a `private readonly ProfileReader $reader`
 * (all tools get one via constructor promotion).
 *
 * @phpstan-import-type StructuredProfile from ProfileReader
 */
trait ResolvesProfile
{
    use JsonResponse;

    /**
     * @return StructuredProfile|string the profile, or a JSON error message when it could not be read
     */
    private function resolveProfile(?string $token, ?string $urlFilter): array|string
    {
        if (null === $token || '' === $token) {
            $token = $this->reader->latestToken($urlFilter ?? '');
        }
        if (null === $token) {
            return $this->json(['error' => 'No requests found — make a request to the app first.']);
        }
        try {
            $profile = $this->reader->read($token);
        } catch (ProfileTooLargeException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }
        if (null === $profile) {
            return $this->json(['error' => "No profile found for token {$token}."]);
        }

        return $profile;
    }
}
