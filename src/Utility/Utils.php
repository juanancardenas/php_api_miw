<?php

namespace App\Utility;

use App\Entity\{ Message, Result};
use JMS\Serializer\SerializerBuilder as JMSSerializer;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Class Utils
 *
 * @package App\Utility
 */
readonly class Utils
{
    /**
     * Generates a response object with the message and corresponding
     * response code (serialized according to _$format_)
     *
     * @param int $code HTTP status
     * @param array<string,mixed>|object|null $messageBody HTTP body message
     * @param null|string $format Default JSON
     * @param null|string[] $headers
     * @return Response Response object
     */
    public static function apiResponse(
        int $code,
        array|object|null $messageBody = null,
        ?string $format = 'json',
        ?array $headers = null
    ): Response {
        if (null === $messageBody) {
            $data = null;
        } else {
            $serializer = JMSSerializer::create()->build();
            $data = $serializer->serialize($messageBody, $format);
        }

        $response = new Response($data, $code);
        $response->headers->add([
            'Access-Control-Allow-Origin' => '*',   // enable CORS
            'Access-Control-Allow-Credentials' => 'true', // Ajax CORS requests with Authorization header
        ]);
        empty($headers) ?: $response->headers->add($headers);
        $response->headers->set(
            'Content-Type',
            match ($format) {
                'xml' => 'application/xml',
                // 'yml' => 'application/yaml',
                default => 'application/json',
            }
        );

        return $response;
    }

    /**
     * Return the request format [ xml | json ]
     *
     * @return string [ xml | json ]
     */
    public static function getFormat(Request $request): string
    {
        $acceptHeader = $request->getAcceptableContentTypes();
        $miFormato = ('application/xml' === ($acceptHeader[0] ?? null))
            ? 'xml'
            : 'json';

        return $request->attributes->get('format') ?? $miFormato;
    }

    /**
     * Generates an Error Response Message
     */
    public static function errorMessage(int $statusCode, ?string $customMessage, string $format): Response
    {
        $customMessage = new Message(
            $statusCode,
            $customMessage ?? strtoupper(Response::$statusTexts[$statusCode])
        );
        return Utils::apiResponse(
            $customMessage->getCode(),
            $customMessage,
            $format
        );
    }

    /**
     * Generar un código ETag para un resultado
     * @param Result $result
     * @return string
     */
    public static function generateResultETag(Result $result): string
    {
        return md5(sprintf(
            '%d-%d-%s',
            $result->getId(),
            $result->getResult(),
            $result->getTime()->getTimestamp()
        ));
    }

    /**
     * Generar un código ETag para una colección de resultados
     * @param Result[] $results
     * @return string
     */
    public static function generateResultsCollectionETag(array $results): string
    {
        $parts = [];

        foreach ($results as $result) {
            $parts[] = sprintf(
                '%d-%d-%s',
                $result->getId(),
                $result->getResult(),
                $result->getTime()->getTimestamp()
            );
        }

        // El orden importa en CGET → garantizamos orden estable
        sort($parts);

        return md5(implode('|', $parts));
    }
}
