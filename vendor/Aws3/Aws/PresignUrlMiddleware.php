<?php

namespace DeliciousBrains\WP_Offload_Media\Aws3\Aws;

use DeliciousBrains\WP_Offload_Media\Aws3\Aws\Signature\SignatureV4;
use DeliciousBrains\WP_Offload_Media\Aws3\Aws\Endpoint\EndpointProvider;
use DeliciousBrains\WP_Offload_Media\Aws3\GuzzleHttp\Psr7\Uri;
use DeliciousBrains\WP_Offload_Media\Aws3\Psr\Http\Message\RequestInterface;
/**
 * @internal Adds computed values to service operations that need presigned url.
 */
class PresignUrlMiddleware
{
    private $client;
    private $endpointProvider;
    private $nextHandler;
    /** @var array names of operations that require presign url */
    private $commandPool;
    /** @var string */
    private $serviceName;
    /** @var string */
    private $presignParam;
    /** @var bool */
    private $requireDifferentRegion;
    public function __construct(array $options, callable $endpointProvider, \DeliciousBrains\WP_Offload_Media\Aws3\Aws\AwsClientInterface $client, callable $nextHandler)
    {
        $this->endpointProvider = $endpointProvider;
        $this->client = $client;
        $this->nextHandler = $nextHandler;
        $this->commandPool = $options['operations'];
        $this->serviceName = $options['service'];
        $this->presignParam = $options['presign_param'];
        $this->requireDifferentRegion = !empty($options['require_different_region']);
    }
    public static function wrap(\DeliciousBrains\WP_Offload_Media\Aws3\Aws\AwsClientInterface $client, callable $endpointProvider, array $options = [])
    {
        return function (callable $handler) use($endpointProvider, $client, $options) {
            $f = new \DeliciousBrains\WP_Offload_Media\Aws3\Aws\PresignUrlMiddleware($options, $endpointProvider, $client, $handler);
            return $f;
        };
    }
    public function __invoke(\DeliciousBrains\WP_Offload_Media\Aws3\Aws\CommandInterface $cmd, \DeliciousBrains\WP_Offload_Media\Aws3\Psr\Http\Message\RequestInterface $request = null)
    {
        if (in_array($cmd->getName(), $this->commandPool) && !isset($cmd->{'__skip' . $cmd->getName()})) {
            $cmd['DestinationRegion'] = $this->client->getRegion();
            if (!$this->requireDifferentRegion || !empty($cmd['SourceRegion']) && $cmd['SourceRegion'] !== $cmd['DestinationRegion']) {
                $cmd[$this->presignParam] = $this->createPresignedUrl($this->client, $cmd);
            }
        }
        $f = $this->nextHandler;
        return $f($cmd, $request);
    }
    private function createPresignedUrl(\DeliciousBrains\WP_Offload_Media\Aws3\Aws\AwsClientInterface $client, \DeliciousBrains\WP_Offload_Media\Aws3\Aws\CommandInterface $cmd)
    {
        $cmdName = $cmd->getName();
        $newCmd = $client->getCommand($cmdName, $cmd->toArray());
        // Avoid infinite recursion by flagging the new command.
        $newCmd->{'__skip' . $cmdName} = true;
        // Serialize a request for the operation.
        $request = \DeliciousBrains\WP_Offload_Media\Aws3\Aws\serialize($newCmd);
        // Create the new endpoint for the target endpoint.
        $endpoint = \DeliciousBrains\WP_Offload_Media\Aws3\Aws\Endpoint\EndpointProvider::resolve($this->endpointProvider, ['region' => $cmd['SourceRegion'], 'service' => $this->serviceName])['endpoint'];
        // Set the request to hit the target endpoint.
        $uri = $request->getUri()->withHost((new \DeliciousBrains\WP_Offload_Media\Aws3\GuzzleHttp\Psr7\Uri($endpoint))->getHost());
        $request = $request->withUri($uri);
        // Create a presigned URL for our generated request.
        $signer = new \DeliciousBrains\WP_Offload_Media\Aws3\Aws\Signature\SignatureV4($this->serviceName, $cmd['SourceRegion']);
        return (string) $signer->presign(\DeliciousBrains\WP_Offload_Media\Aws3\Aws\Signature\SignatureV4::convertPostToGet($request), $client->getCredentials()->wait(), '+1 hour')->getUri();
    }
}
