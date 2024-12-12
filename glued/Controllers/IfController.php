<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Glued\Lib\Controllers\AbstractIf;
use JsonPath\JsonObject;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Selective\Transformer\ArrayTransformer;


class IfController extends AbstractIf
{

    private $action;

    public function __construct(ContainerInterface $c)
    {
        parent::__construct($c);
    }


    private function transform($data)
    {
        $objs = [];
        $transformer = new ArrayTransformer();
        $transformer
            ->set('props',['legal'])
            ->set('id.0.countrycode','CZ')
            ->map('id.0.regid', 'ico')
            ->map('id.0.vatid', 'dic')
            ->map('id.0.registry.iat', 'datumVzniku')
            ->map('id.0.registry.uat', 'datumAktualizace')
            ->map('id.0.registry.eat', 'datumZaniku')
            ->map('name.0.val', 'obchodniJmeno')
            ->map('name.0.props.0', 'obchodniJmeno',
                $transformer->rule()->callback(function ($v) { return 'principal'; } ))
            ->map('address.0.kind', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return 'postal'; } ))
            ->map('address.0.val', 'sidlo.textovaAdresa')
            ->map('address.0.countrycode','sidlo.kodStatu')
            ->map('address.0.region', 'sidlo.nazevKraje')
            ->map('address.0.district', 'sidlo.nazevOkresu')
            ->map('address.0.municipality', 'sidlo.nazevObce')
            ->map('address.0.street', 'sidlo.nazevUlice')
            ->map('address.0.conscriptionnumber', 'sidlo.cisloDomovni')
            ->map('address.0.streetnumber', 'sidlo.cisloOrientacni')
            ->map('address.0.suburb', 'sidlo.nazevCastiObce')
            ->map('address.0.postcode', 'sidlo.psc')
            ->set('address.0.props', ['principal'])
            ->map('address.1.val', 'adresaDorucovaci.textovaAdresa')
            ->map('address.1.props', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return ['forwarding']; } ))

            ;
        $data = json_decode($data, true);
        foreach ($data['ekonomickeSubjekty'] as $item) {
            $i = new JsonObject($item, true);
            $obj = $transformer->toArray($item);
            if ($i->{'$.dalsiUdaje.*.spisovaZnacka'}) { $obj['id'][0]['registry']['file'] = $i->{'$.dalsiUdaje.*.spisovaZnacka'}[0]; }
            $obj['id'][0]['val'] = implode(", ", [ $obj['id'][0]['regid'] ?? null, $obj['id'][0]['vatid'] ?? null, $obj['id'][0]['registry']['file'] ?? null ]);
            $objs[] = $obj;
        }
        return $objs;
    }





    //private function fetch(string $id, &$result_raw = null) :? mixed
    private function fetch(array $q) : mixed
    {
        $uri = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat';
        $qValid = false;
        $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_contacts_import_v1']['pattern'];
        $base = str_replace('{act}/{key}', '', $base);
        $reqbody = [
            'pocet' => 10,
            'razeni' => []
        ];

        if (array_key_exists('q', $q)) {
            $qValid = true;
            if (is_array($q['q'])) { throw new \Exception('Only a single q parameter is allowed', 400); }
            $reqbody["obchodniJmeno"] = $q['q'];
            if (mb_strlen($q['q'], 'UTF-8') < 2) { throw new \Exception('Query string too short'); }
        }
        if (array_key_exists('regid', $q)) {
            $qValid = true;
            if (is_string($q['regid'])) { $regids[] = $q['regid']; }
            else { $regids = $q['regid']; }
            foreach ($regids as $regidtest) {
                if (!preg_match('/^\d{8}$/', $regidtest)) { throw new \Exception("Regid `$regidtest` is not valid.", 400); }
            }
            $reqbody["ico"] = $regids;
        }
        if (!$qValid) { throw new \Exception('Query request params `q` or `regid` missing.', 400); }


        $content = json_encode($reqbody);
        $key = hash('md5', $uri . $content);

        if ($this->fscache->has($key)) {
            $response = $this->fscache->get($key);
            $final = $this->transform($response);
            foreach ($final as $k => &$f) {
                $fid = $f['id'][0]['regid'] ?? false;
                if (!$fid) {
                    // clear items without a regid, skip saving etc.
                    unset($final[$k]);
                    continue;
                }
                $this->cacheValidActionsresponse($this->action, resPayload: $f, fid: $fid);
                $f['import'] = "{$base}{$this->action}/{$fid}";
            }
            return array_values($final); // reindex array (when keys are unset)
        }

        try {
            $client = new HttpBrowser(HttpClient::create(['timeout' => 20]));
            $client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:113.0) Gecko/20100101 Firefox/113.0');
            $client->setServerParameter('CONTENT_TYPE', 'application/json');
            $client->setServerParameter('HTTP_ACCEPT', 'application/json');
            $response = $client->request(method: 'POST', uri: $uri, parameters: [], files: [], server: [], content: $content);
        } catch (\Exception $e) {
            return null;
        }
        $response = $client->getResponse()->getContent() ?? null;
        $this->fscache->set($key, $response, 3600);
        $final = $this->transform($response);

        foreach ($final as $k => &$f) {
            $fid = $f['id'][0]['regid'] ?? false;
            if (!$fid) {
                // clear items without a regid, skip saving etc.
                unset($final[$k]);
                continue;
            }
            $this->cacheValidActionsresponse($this->action, resPayload: $f, fid: $fid);
            $f['import'] = "{$base}{$this->action}/{$fid}";
        }
        return array_values($final); // reindex array (when keys are unset)
    }


    public function query(Request $request, Response $response, array $args = []): Response
    {
        $this->action = $this->getActionUUID($args['deployment'], (string) $request->getUri()->getPath(), (string) $request->getMethod());
        $p = $request->getQueryParams();
        $fp = [];
        if (isset($p['regid'])) { $fp['regid'] = $p['regid']; }
        if (isset($p['q'])) { $fp['q'] = $p['q']; }
        $res = $this->fetch($fp);
        if (count($res)<1) {return $response->withJson(['code' => 404])->withStatus(404);}
        return $response->withJson($res);
    }

    public function resetCache(Request $request, Response $response, array $args = []): Response
    {
        $clr = $this->fscache->clear();
        $res = [
            'service' => 'if/ares_gov_cz',
            'endpoint' => 'Invalidate cache',
            'result' => $clr
        ];
        return $response->withJson($res);
    }


}
