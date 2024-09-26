<?php

declare(strict_types=1);

namespace TwigStan;

use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use TwigStan\Application\TwigStanAnalysisResult;
use TwigStan\Application\TwigStanError;

final readonly class ErrorHelper
{
    public static function assertAnalysisResultMatchesJsonFile(TwigStanAnalysisResult $result, string $directory): void
    {
        $filesystem = new Filesystem();
        $expectedErrors = json_decode(
            $filesystem->readFile(Path::join($directory, 'errors.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $actual = self::toArray($result, $directory);

        $expectedButNotActual = [];
        $actualErrorsNotExpected = $actual['errors'];
        foreach ($expectedErrors['errors'] as $expectedError) {
            $key = array_search($expectedError, $actualErrorsNotExpected, true);
            if ($key !== false) {
                unset($actualErrorsNotExpected[$key]);
                continue;
            }

            $expectedButNotActual[] = $expectedError;
        }
        $actualErrorsNotExpected = array_values($actualErrorsNotExpected);

        Assert::assertTrue(
            $expectedButNotActual === [] && $actualErrorsNotExpected === [],
            sprintf(
                "The following errors were expected but not found: %s\n\nThe following errors were found but not expected: %s",
                json_encode(
                    $expectedButNotActual,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
                ),
                json_encode(
                    $actualErrorsNotExpected,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
                ),
            ),
        );

        Assert::assertEqualsCanonicalizing(
            $expectedErrors['fileSpecificErrors'],
            $actual['fileSpecificErrors'],
            sprintf(
                'FileSpecificErrors do not match with expectations. The full actual result is: %s',
                json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            ),
        );
    }

    /**
     * @return array{
     *     errors: list<array{
     *          message: string,
     *          identifier: string|null,
     *          tip: string|null,
     *          twigSourceLocation: string|null,
     *          renderPoints: array<string>,
     *     }>,
     *     fileSpecificErrors: list<string>,
     * }
     */
    private static function toArray(TwigStanAnalysisResult $result, string $directory): array
    {
        return [
            'errors' => array_map(
                fn($error) => self::errorToArray($error, $directory),
                $result->errors,
            ),
            'fileSpecificErrors' => $result->fileSpecificErrors,
        ];
    }

    /**
     * @return array{
     *      message: string,
     *      identifier: string|null,
     *      tip: string|null,
     *      twigSourceLocation: string|null,
     *      renderPoints: array<string>,
     * }
     */
    private static function errorToArray(TwigStanError $error, string $directory): array
    {
        return [
            'message' => $error->message,
            'identifier' => $error->identifier,
            'tip' => $error->tip,
            'twigSourceLocation' => $error->twigSourceLocation?->toString($directory),
            'renderPoints' => array_map(
                fn($sourceLocation) => $sourceLocation->toString($directory),
                $error->renderPoints,
            ),
        ];
    }
}