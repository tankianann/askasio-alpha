<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Repositories\ChatbotIntegrationCredentialRepositoryInterface;
use App\Support\Pagination\PageRequest;
use App\Support\Pagination\PaginatedResult;

final class InMemoryChatbotIntegrationCredentialRepository implements ChatbotIntegrationCredentialRepositoryInterface
{
    /** @var array<int,ChatbotIntegrationCredential> */ public array $items=[];
    public function paginate(PageRequest $page):PaginatedResult{return new PaginatedResult(array_values($this->items),count($this->items),$page->clampToTotal(count($this->items)));}
    public function findById(int $id):?ChatbotIntegrationCredential{return $this->items[$id]??null;}
    public function findByHash(string $hash):?ChatbotIntegrationCredential{foreach($this->items as $item){if($item->secretHash===$hash){return $item;}}return null;}
    public function create(int $adminId,string $name,string $prefix,string $hash,?string $expiresAt,array $chatbotIds):ChatbotIntegrationCredential{$id=count($this->items)+1;return $this->items[$id]=new ChatbotIntegrationCredential($id,$adminId,$name,$prefix,$hash,'active',$chatbotIds,0,0,'2026-07-21 00:00:00',null,$expiresAt,null);}
    public function touchUsage(int $id,int $providerTokens=0):void{$c=$this->items[$id];$this->items[$id]=new ChatbotIntegrationCredential($c->id,$c->createdByAdminId,$c->name,$c->visiblePrefix,$c->secretHash,$c->status,$c->chatbotIds,$c->requestCount+1,$c->providerTokens+$providerTokens,$c->createdAt,'2026-07-21 01:00:00',$c->expiresAt,$c->revokedAt);}
    public function addProviderTokens(int $id,int $providerTokens):void{$c=$this->items[$id];$this->items[$id]=new ChatbotIntegrationCredential($c->id,$c->createdByAdminId,$c->name,$c->visiblePrefix,$c->secretHash,$c->status,$c->chatbotIds,$c->requestCount,$c->providerTokens+$providerTokens,$c->createdAt,$c->lastUsedAt,$c->expiresAt,$c->revokedAt);}
    public function revoke(int $id):void{$c=$this->items[$id];$this->items[$id]=new ChatbotIntegrationCredential($c->id,$c->createdByAdminId,$c->name,$c->visiblePrefix,$c->secretHash,'revoked',$c->chatbotIds,$c->requestCount,$c->providerTokens,$c->createdAt,$c->lastUsedAt,$c->expiresAt,'2026-07-21 01:00:00');}
    public function delete(int $id):void{unset($this->items[$id]);}
}
