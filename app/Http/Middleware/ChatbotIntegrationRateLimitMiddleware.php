<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApiRateLimitRepositoryInterface;
use Closure;

final readonly class ChatbotIntegrationRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private ApiRateLimitRepositoryInterface $rates, private string $secret, private int $window, private int $perCredential, private int $perIp)
    {
        if (strlen($this->secret) < 32 || $this->window < 1 || $this->window > 3600 || $this->perCredential < 1 || $this->perIp < 1) {
            throw new \InvalidArgumentException('Integration rate-limit configuration is invalid.');
        }
    }
    public function process(Request $request, Closure $next): Response
    {
        $credential=$request->attribute('chatbot_integration_credential');
        if (!$credential instanceof ChatbotIntegrationCredential) { return $next($request); }
        $now=time(); $start=intdiv($now,$this->window)*$this->window; $started=gmdate('Y-m-d H:i:s',$start); $expires=gmdate('Y-m-d H:i:s',$start+$this->window);
        $credentialCount=$this->rates->consume('integration',hash_hmac('sha256','integration:'.$credential->id,$this->secret),$started,$expires);
        $ipCount=$this->rates->consume('ip',hash_hmac('sha256','integration:ip:'.$request->clientIp(),$this->secret),$started,$expires);
        $remaining=max(0,min($this->perCredential-$credentialCount,$this->perIp-$ipCount)); $limit=min($this->perCredential,$this->perIp);
        if ($credentialCount>$this->perCredential || $ipCount>$this->perIp) {
            $id=$request->attribute('request_id');
            return Response::json(['error'=>['code'=>'rate_limit_exceeded','message'=>'Too many integration requests.','request_id'=>is_string($id)?$id:null]],429)
                ->withHeader('Retry-After',(string)max(1,$start+$this->window-$now))->withHeader('X-RateLimit-Limit',(string)$limit)->withHeader('X-RateLimit-Remaining','0')->withHeader('Cache-Control','no-store');
        }
        return $next($request)->withHeader('X-RateLimit-Limit',(string)$limit)->withHeader('X-RateLimit-Remaining',(string)$remaining);
    }
}
