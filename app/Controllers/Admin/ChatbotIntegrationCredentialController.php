<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ChatbotIntegrationCredentialRepositoryInterface;
use App\Repositories\ChatbotRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotIntegrationCredentialService;
use App\Support\Pagination\PageRequest;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

final readonly class ChatbotIntegrationCredentialController
{
    private const FLASH='_flash_integration_credential';
    public function __construct(private ChatbotIntegrationCredentialRepositoryInterface $credentials,private ChatbotIntegrationCredentialService $service,private ChatbotRepositoryInterface $chatbots,private ViewRenderer $views,private CsrfTokenManager $csrf,private SessionStoreInterface $session,private string $environment,private string $timezone,private LoggerInterface $logger){}
    public function index(Request $request):Response
    {
        $page=filter_var($request->query('page',1),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
        $result=$this->credentials->paginate(new PageRequest((int)$page,25,[25]));
        if($result->pageRequest->page!==(int)$page){return Response::redirect('/admin/integration-credentials');}
        return Response::html($this->views->render('integration_credentials/index',[...$this->layout($request,'Integration credentials'),'page'=>$result,'chatbots'=>array_column($this->chatbots->listOptions(),'name','id'),'message'=>$this->session->pull(self::FLASH),'queryUrl'=>static fn(array $overrides=[]):string=>'/admin/integration-credentials'.(isset($overrides['page'])?'?page='.(int)$overrides['page']:'')],'layouts/admin'));
    }
    public function create(Request $request):Response{return $this->form($request);}
    public function store(Request $request):Response
    {
        $name=is_string($request->input('name'))?(string)$request->input('name'):''; $expiry=is_string($request->input('expires_at'))?trim((string)$request->input('expires_at')):''; $selected=$request->input('chatbot_ids',[]);
        $ids=is_array($selected)?array_values(array_filter(array_map(static fn(mixed $v):int=>is_string($v)&&ctype_digit($v)?(int)$v:0,$selected))):[];
        $allowed=array_column($this->chatbots->listOptions(),'id'); if(array_diff($ids,$allowed)!==[]){return $this->form($request,'One or more chatbot scopes are invalid.',['name'=>$name,'expires_at'=>$expiry,'chatbot_ids'=>$ids],422);}
        try{$expiresAt=$this->expiry($expiry);$created=$this->service->create($this->admin($request)->id,$name,$expiresAt,$ids);}catch(ValidationException $e){return $this->form($request,$e->getMessage(),['name'=>$name,'expires_at'=>$expiry,'chatbot_ids'=>$ids],422);}
        $this->logger->info('Chatbot integration credential created.',['admin_user_id'=>$this->admin($request)->id,'credential_id'=>$created->credential->id,'chatbot_scope_count'=>count($created->credential->chatbotIds),'expires_at'=>$created->credential->expiresAt]);
        return Response::html($this->views->render('integration_credentials/created',[...$this->layout($request,'Integration credential created'),'created'=>$created],'layouts/admin'),201)->withHeader('Cache-Control','no-store');
    }
    public function revoke(Request $request):Response{$c=$this->credential($request);$this->credentials->revoke($c->id);$this->logger->notice('Chatbot integration credential revoked.',['admin_user_id'=>$this->admin($request)->id,'credential_id'=>$c->id]);$this->session->put(self::FLASH,'Integration credential “'.$c->name.'” revoked.');return Response::redirect('/admin/integration-credentials',303);}
    public function delete(Request $request):Response{$c=$this->credential($request);$this->credentials->delete($c->id);$this->logger->notice('Chatbot integration credential deleted.',['admin_user_id'=>$this->admin($request)->id,'credential_id'=>$c->id]);$this->session->put(self::FLASH,'Integration credential “'.$c->name.'” permanently deleted.');return Response::redirect('/admin/integration-credentials',303);}
    /** @param array<string,mixed> $old */
    private function form(Request $request,?string $error=null,array $old=[],int $status=200):Response{return Response::html($this->views->render('integration_credentials/create',[...$this->layout($request,'Create integration credential'),'chatbots'=>$this->chatbots->listOptions(),'error'=>$error,'old'=>$old],'layouts/admin'),$status);}
    private function credential(Request $request):ChatbotIntegrationCredential{$id=(string)$request->route('credentialId');$c=ctype_digit($id)?$this->credentials->findById((int)$id):null;return $c??throw new HttpException(404,'The integration credential was not found.','integration_credential_not_found');}
    private function expiry(string $value):?string{if($value===''){return null;}$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone($this->timezone));if(!$date instanceof DateTimeImmutable){throw new ValidationException('Choose a valid expiry date and time.');}return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    /** @return array<string,mixed> */ private function layout(Request $request,string $title):array{return['title'=>$title,'admin'=>$this->admin($request),'csrfToken'=>$this->csrf->token(),'environment'=>$this->environment,'currentSection'=>'integration_credentials'];}
    private function admin(Request $request):AdminUser{$a=$request->attribute('admin_user');return $a instanceof AdminUser?$a:throw new \LogicException('Authenticated administrator is missing.');}
}
