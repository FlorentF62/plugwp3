<?php

/**
 * Copyright 2015 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core;

use DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\FetchAuthTokenInterface;
use DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\HttpHandler\Guzzle5HttpHandler;
use DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\HttpHandler\Guzzle6HttpHandler;
use DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\HttpHandler\HttpHandlerFactory;
use DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\RequestWrapperTrait;
use DeliciousBrains\WP_Offload_Media\Gcp\GuzzleHttp\Exception\RequestException;
use DeliciousBrains\WP_Offload_Media\Gcp\GuzzleHttp\Promise\PromiseInterface;
use DeliciousBrains\WP_Offload_Media\Gcp\GuzzleHttp\Psr7;
use DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\RequestInterface;
use DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\ResponseInterface;
use DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\StreamInterface;
/**
 * The RequestWrapper is responsible for delivering and signing requests.
 */
class RequestWrapper
{
    use RequestWrapperTrait;
    use RetryDeciderTrait;
    /**
     * @var string|null The current version of the component from which the request
     * originated.
     */
    private $componentVersion;
    /**
     * @var string|null Access token used to sign requests.
     */
    private $accessToken;
    /**
     * @var callable A handler used to deliver PSR-7 requests specifically for
     * authentication.
     */
    private $authHttpHandler;
    /**
     * @var callable A handler used to deliver PSR-7 requests.
     */
    private $httpHandler;
    /**
     * @var callable A handler used to deliver PSR-7 requests asynchronously.
     */
    private $asyncHttpHandler;
    /**
     * @var array HTTP client specific configuration options.
     */
    private $restOptions;
    /**
     * @var bool Whether to enable request signing.
     */
    private $shouldSignRequest;
    /**
     * @var callable Sets the conditions for whether or not a
     * request should attempt to retry.
     */
    private $retryFunction;
    /**
     * @var callable Executes a delay.
     */
    private $delayFunction;
    /**
     * @var callable|null Sets the conditions for determining how long to wait
     * between attempts to retry.
     */
    private $calcDelayFunction;
    /**
     * @param array $config [optional] {
     *     Configuration options. Please see
     *     {@see Google\Cloud\Core\RequestWrapperTrait::setCommonDefaults()} for
     *     the other available options.
     *
     *     @type string $componentVersion The current version of the component from
     *           which the request originated.
     *     @type string $accessToken Access token used to sign requests.
     *     @type callable $asyncHttpHandler *Experimental* A handler used to
     *           deliver PSR-7 requests asynchronously. Function signature should match:
     *           `function (RequestInterface $request, array $options = []) : PromiseInterface<ResponseInterface>`.
     *     @type callable $authHttpHandler A handler used to deliver PSR-7
     *           requests specifically for authentication. Function signature
     *           should match:
     *           `function (RequestInterface $request, array $options = []) : ResponseInterface`.
     *     @type callable $httpHandler A handler used to deliver PSR-7 requests.
     *           Function signature should match:
     *           `function (RequestInterface $request, array $options = []) : ResponseInterface`.
     *     @type array $restOptions HTTP client specific configuration options.
     *     @type bool $shouldSignRequest Whether to enable request signing.
     *     @type callable $restRetryFunction Sets the conditions for whether or
     *           not a request should attempt to retry. Function signature should
     *           match: `function (\Exception $ex) : bool`.
     *     @type callable $restDelayFunction Executes a delay, defaults to
     *           utilizing `usleep`. Function signature should match:
     *           `function (int $delay) : void`.
     *     @type callable $restCalcDelayFunction Sets the conditions for
     *           determining how long to wait between attempts to retry. Function
     *           signature should match: `function (int $attempt) : int`.
     * }
     */
    public function __construct(array $config = [])
    {
        $this->setCommonDefaults($config);
        $config += ['accessToken' => null, 'asyncHttpHandler' => null, 'authHttpHandler' => null, 'httpHandler' => null, 'restOptions' => [], 'shouldSignRequest' => true, 'componentVersion' => null, 'restRetryFunction' => null, 'restDelayFunction' => null, 'restCalcDelayFunction' => null];
        $this->componentVersion = $config['componentVersion'];
        $this->accessToken = $config['accessToken'];
        $this->restOptions = $config['restOptions'];
        $this->shouldSignRequest = $config['shouldSignRequest'];
        $this->retryFunction = $config['restRetryFunction'] ?: $this->getRetryFunction();
        $this->delayFunction = $config['restDelayFunction'] ?: function ($delay) {
            usleep($delay);
        };
        $this->calcDelayFunction = $config['restCalcDelayFunction'];
        $this->httpHandler = $config['httpHandler'] ?: \DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\HttpHandler\HttpHandlerFactory::build();
        $this->authHttpHandler = $config['authHttpHandler'] ?: $this->httpHandler;
        $this->asyncHttpHandler = $config['asyncHttpHandler'] ?: $this->buildDefaultAsyncHandler();
        if ($this->credentialsFetcher instanceof AnonymousCredentials) {
            $this->shouldSignRequest = false;
        }
    }
    /**
     * Deliver the request.
     *
     * @param RequestInterface $request A PSR-7 request.
     * @param array $options [optional] {
     *     Request options.
     *
     *     @type float $requestTimeout Seconds to wait before timing out the
     *           request. **Defaults to** `0`.
     *     @type int $retries Number of retries for a failed request.
     *           **Defaults to** `3`.
     *     @type callable $restRetryFunction Sets the conditions for whether or
     *           not a request should attempt to retry. Function signature should
     *           match: `function (\Exception $ex) : bool`.
     *     @type callable $restDelayFunction Executes a delay, defaults to
     *           utilizing `usleep`. Function signature should match:
     *           `function (int $delay) : void`.
     *     @type callable $restCalcDelayFunction Sets the conditions for
     *           determining how long to wait between attempts to retry. Function
     *           signature should match: `function (int $attempt) : int`.
     *     @type array $restOptions HTTP client specific configuration options.
     * }
     * @return ResponseInterface
     */
    public function send(\DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\RequestInterface $request, array $options = [])
    {
        $retryOptions = $this->getRetryOptions($options);
        $backoff = new \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\ExponentialBackoff($retryOptions['retries'], $retryOptions['retryFunction']);
        if ($retryOptions['delayFunction']) {
            $backoff->setDelayFunction($retryOptions['delayFunction']);
        }
        if ($retryOptions['calcDelayFunction']) {
            $backoff->setCalcDelayFunction($retryOptions['calcDelayFunction']);
        }
        try {
            return $backoff->execute($this->httpHandler, [$this->applyHeaders($request), $this->getRequestOptions($options)]);
        } catch (\Exception $ex) {
            throw $this->convertToGoogleException($ex);
        }
    }
    /**
     * Deliver the request asynchronously.
     *
     * @param RequestInterface $request A PSR-7 request.
     * @param array $options [optional] {
     *     Request options.
     *
     *     @type float $requestTimeout Seconds to wait before timing out the
     *           request. **Defaults to** `0`.
     *     @type int $retries Number of retries for a failed request.
     *           **Defaults to** `3`.
     *     @type callable $restRetryFunction Sets the conditions for whether or
     *           not a request should attempt to retry. Function signature should
     *           match: `function (\Exception $ex) : bool`.
     *     @type callable $restDelayFunction Executes a delay, defaults to
     *           utilizing `usleep`. Function signature should match:
     *           `function (int $delay) : void`.
     *     @type callable $restCalcDelayFunction Sets the conditions for
     *           determining how long to wait between attempts to retry. Function
     *           signature should match: `function (int $attempt) : int`.
     *     @type array $restOptions HTTP client specific configuration options.
     * }
     * @return PromiseInterface<ResponseInterface>
     * @experimental The experimental flag means that while we believe this method
     *      or class is ready for use, it may change before release in backwards-
     *      incompatible ways. Please use with caution, and test thoroughly when
     *      upgrading.
     */
    public function sendAsync(\DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\RequestInterface $request, array $options = [])
    {
        // Unfortunately, the current ExponentialBackoff implementation doesn't
        // play nicely with promises.
        $retryAttempt = 0;
        $fn = function ($retryAttempt) use(&$fn, $request, $options) {
            $asyncHttpHandler = $this->asyncHttpHandler;
            $retryOptions = $this->getRetryOptions($options);
            if (!$retryOptions['calcDelayFunction']) {
                $retryOptions['calcDelayFunction'] = [\DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\ExponentialBackoff::class, 'calculateDelay'];
            }
            return $asyncHttpHandler($this->applyHeaders($request), $this->getRequestOptions($options))->then(null, function (\Exception $ex) use($fn, $retryAttempt, $retryOptions) {
                $shouldRetry = $retryOptions['retryFunction']($ex);
                if ($shouldRetry === false || $retryAttempt >= $retryOptions['retries']) {
                    throw $this->convertToGoogleException($ex);
                }
                $delay = $retryOptions['calcDelayFunction']($retryAttempt);
                $retryOptions['delayFunction']($delay);
                $retryAttempt++;
                return $fn($retryAttempt);
            });
        };
        return $fn($retryAttempt);
    }
    /**
     * Applies headers to the request.
     *
     * @param RequestInterface $request A PSR-7 request.
     * @return RequestInterface
     */
    private function applyHeaders(\DeliciousBrains\WP_Offload_Media\Gcp\Psr\Http\Message\RequestInterface $request)
    {
        $headers = ['User-Agent' => 'gcloud-php/' . $this->componentVersion, 'x-goog-api-client' => 'gl-php/' . PHP_VERSION . ' gccl/' . $this->componentVersion];
        if ($this->shouldSignRequest) {
            $headers['Authorization'] = 'Bearer ' . $this->getToken();
        }
        return \DeliciousBrains\WP_Offload_Media\Gcp\GuzzleHttp\Psr7\modify_request($request, ['set_headers' => $headers]);
    }
    /**
     * Gets the access token.
     *
     * @return string
     */
    private function getToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }
        return $this->fetchCredentials()['access_token'];
    }
    /**
     * Fetches credentials.
     *
     * @return array
     */
    private function fetchCredentials()
    {
        $backoff = new \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\ExponentialBackoff($this->retries, $this->getRetryFunction());
        try {
            return $backoff->execute([$this->getCredentialsFetcher(), 'fetchAuthToken'], [$this->authHttpHandler]);
        } catch (\Exception $ex) {
            throw $this->convertToGoogleException($ex);
        }
    }
    /**
     * Convert any exception to a Google Exception.
     *
     * @param \Exception $ex
     * @return Exception\ServiceException
     */
    private function convertToGoogleException(\Exception $ex)
    {
        switch ($ex->getCode()) {
            case 400:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\BadRequestException::class;
                break;
            case 404:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\NotFoundException::class;
                break;
            case 409:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\ConflictException::class;
                break;
            case 412:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\FailedPreconditionException::class;
                break;
            case 500:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\ServerException::class;
                break;
            case 504:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\DeadlineExceededException::class;
                break;
            default:
                $exception = \DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Exception\ServiceException::class;
                break;
        }
        return new $exception($this->getExceptionMessage($ex), $ex->getCode(), $ex);
    }
    /**
     * Gets the exception message.
     *
     * @access private
     * @param \Exception $ex
     * @return string
     */
    private function getExceptionMessage(\Exception $ex)
    {
        if ($ex instanceof RequestException && $ex->hasResponse()) {
            return (string) $ex->getResponse()->getBody();
        }
        return $ex->getMessage();
    }
    /**
     * Gets a set of request options.
     *
     * @param array $options
     * @return array
     */
    private function getRequestOptions(array $options)
    {
        $restOptions = isset($options['restOptions']) ? $options['restOptions'] : $this->restOptions;
        $timeout = isset($options['requestTimeout']) ? $options['requestTimeout'] : $this->requestTimeout;
        if ($timeout && !array_key_exists('timeout', $restOptions)) {
            $restOptions['timeout'] = $timeout;
        }
        return $restOptions;
    }
    /**
     * Gets a set of retry options.
     *
     * @param array $options
     * @return array
     */
    private function getRetryOptions(array $options)
    {
        return ['retries' => isset($options['retries']) ? $options['retries'] : $this->retries, 'retryFunction' => isset($options['restRetryFunction']) ? $options['restRetryFunction'] : $this->retryFunction, 'delayFunction' => isset($options['restDelayFunction']) ? $options['restDelayFunction'] : $this->delayFunction, 'calcDelayFunction' => isset($options['restCalcDelayFunction']) ? $options['restCalcDelayFunction'] : $this->calcDelayFunction];
    }
    /**
     * Builds the default async HTTP handler.
     *
     * @return callable
     */
    private function buildDefaultAsyncHandler()
    {
        $isGuzzleHandler = $this->httpHandler instanceof Guzzle6HttpHandler || $this->httpHandler instanceof Guzzle5HttpHandler;
        return $isGuzzleHandler ? [$this->httpHandler, 'async'] : [\DeliciousBrains\WP_Offload_Media\Gcp\Google\Auth\HttpHandler\HttpHandlerFactory::build(), 'async'];
    }
}
