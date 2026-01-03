<?php

namespace App\Controller;

use App\Entity\Result;
use App\Utility\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{Exception\JsonException, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Class ApiResultsQueryController
 *
 * @package App\Controller
 */
#[Route(
    path: ApiResultsQueryInterface::RUTA_API,
    name: 'api_results_'
)]
class ApiResultsQueryController extends AbstractController implements ApiResultsQueryInterface
{
    private const string HEADER_CACHE_CONTROL = 'Cache-Control';
    private const string HEADER_ETAG = 'ETag';
    private const string HEADER_ALLOW = 'Allow';

    /**
     * Constructor de la clase que gestiona los comandos tipo Query (CGET, GET y OPTIONS) y que
     * inyecta el EntityManager para que usado en los métodos de implentación de operación HTTP
     * @param EntityManagerInterface $entityManager
     */
    public function __construct( private readonly EntityManagerInterface $entityManager ) {
    }

    /**
     * Implementación de la operación CGET
     * @see ApiResultsQueryInterface::cgetAction()
     * @throws JsonException
     */
    #[Route(
        path: ".{_format}/{sort?id}",
        name: 'cget',
        requirements: [
            'sort' => "id|user|result",
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => 'json', 'sort' => 'id' ],
        methods: [ Request::METHOD_GET, Request::METHOD_HEAD ],
    )]
    public function cgetAction(Request $request): Response
    {
        $format = Utils::getFormat($request);
        if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return Utils::errorMessage( // 401: No Authorized
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED: Invalid credentials.',
                $format
            );
        }

        $order = strval($request->attributes->get('sort'));
        $results = $this->entityManager
            ->getRepository(Result::class)
            ->findBy([], [ $order => 'ASC' ]);

        // No hay resultados
        if (empty($results)) {
            return Utils::errorMessage(Response::HTTP_NOT_FOUND, null, $format); // 404: Not found
        }

        // Caching with ETag
        $etag = md5((string) json_encode($results, JSON_THROW_ON_ERROR));
        if (($etags = $request->getETags()) && (in_array($etag, $etags) || in_array('*', $etags))) {
            return new Response()->setNotModified(); // 304: Not Modified
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [ 'results' => array_map(fn ($res) =>  ['result' => $res], $results) ]  // GET method
                : null, // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * Implementación de la operación GET
     * @see ApiResultsQueryInterface::getAction()
     * @throws JsonException
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'get',
        requirements: [
            "resultId" => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => 'json' ],
        methods: [ Request::METHOD_GET, Request::METHOD_HEAD ],
    )]
    public function getAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);
        if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return Utils::errorMessage( // 401: Unauthorized
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED: Invalid credentials.',
                $format
            );
        }

        /** @var Result $result */
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->find($resultId);

        // No hay resultado
        if (!$result instanceof Result) {
            return Utils::errorMessage(Response::HTTP_NOT_FOUND, null, $format);    // 404: Not found
        }

        // Caching with ETag
        $etag = md5(json_encode($result, JSON_THROW_ON_ERROR));
        if (($etags = $request->getETags()) && (in_array($etag, $etags) || in_array('*', $etags))) {
            return (new Response())->setNotModified(); // 304: Not Modified
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [ Result::RESULT_ATTR => $result ]  // GET method
                : null,                               // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * Implementación de la operación OPTIONS
     * @see ApiResultsQueryInterface::optionsAction()
     * @throws JsonException
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'options',
        requirements: [
            'resultId' => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ 'resultId' => 0, '_format' => 'json' ],
        methods: [ Request::METHOD_OPTIONS ],
    )]
    public function optionsAction(int|null $resultId): Response
    {
        $methods = $resultId !== 0
            ? [ Request::METHOD_GET, Request::METHOD_PUT, Request::METHOD_DELETE ]
            : [ Request::METHOD_GET, Request::METHOD_POST ];
        $methods[] = Request::METHOD_OPTIONS;

        return new Response(
            null,
            Response::HTTP_NO_CONTENT,
            [
                self::HEADER_ALLOW => implode(',', $methods),
                self::HEADER_CACHE_CONTROL => 'public, inmutable'
            ]
        );
    }
}
