<?php

declare(strict_types=1);

namespace Glued\Controllers;

use JsonPath\JsonObject;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Selective\Transformer\ArrayTransformer;


class IfController extends AbstractController
{

    private $action;
    private $service;
    private $q;

    public function __construct(ContainerInterface $c)
    {
        parent::__construct($c);
        $this->action =  'd65d2468-afe0-40c2-986c-e67047141013';
        $this->service = '39542e95-db70-4fd1-bba8-2cb52870dffd';
        $this->q = "INSERT INTO t_if__objects (c_action, c_fid, c_data, c_run) 
              VALUES (uuid_to_bin(?, true), ?, ?, uuid_to_bin(?, true)) 
              ON DUPLICATE KEY UPDATE
              c_rev = IF(c_data != VALUES(c_data), c_rev + 1, c_rev),
              c_run = IF(c_data != VALUES(c_data), VALUES(c_run), c_run),
              c_data = IF(c_data != VALUES(c_data), VALUES(c_data), c_data);";
    }



    private function transform($data)
    {
        $objs = [];
        $transformer = new ArrayTransformer();
        $transformer
            ->set('domicile', 'CZ')
            ->map('regid.val', 'ico')
            ->map('vatid.val', 'dic')
            ->map('name.0.val', 'obchodniJmeno')
            ->map('name.0.kind', 'obchodniJmeno',
                $transformer->rule()->callback(function ($v) { return 'business'; } ))
            ->map('address.0.kind', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return 'business'; } ))
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
            ->set('address.0.kind', 'business')
            ->map('address.1.val', 'adresaDorucovaci.textovaAdresa')
            ->map('address.1.kind', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return 'forwarding'; } ))
            ->map('registration.0.date.establishing', 'datumVzniku')
            ->map('registration.0.date.update', 'datumAktualizace')
            ->map('registration.0.date.termination', 'datumZaniku')
            ->set('registration.0.kind', 'business');
        $data = json_decode($data, true);
        foreach ($data['ekonomickeSubjekty'] as $item) {
            $i = new JsonObject($item, true);
            $obj = $transformer->toArray($item);
            if ($i->{'$.dalsiUdaje.*.spisovaZnacka'}) { $obj['registration'][0]['val'] = $i->{'$.dalsiUdaje.*.spisovaZnacka'}[0]; }
            $objs[] = $obj;
        }
        return $objs;
    }

    //private function fetch(string $id, &$result_raw = null) :? mixed
    private function fetch(string $q) : mixed
    {
        $uri = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat';
        $content = '{"obchodniJmeno":"' . $q . '","pocet":10,"start":0,"razeni":[]}';
        $key = hash('md5', $uri . $content);
        if (mb_strlen($q, 'UTF-8') < 2) {
            throw new \Exception('Query string too short', 400);
        }

        if ($this->fscache->has($key)) {
            $response = $this->fscache->get($key);
            $final = $this->transform($response);
            foreach ($final as &$f) {
                $fid = $f['regid']['val'];
                $f['save'] = $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_contacts_import_v1']['path'] . '/';
                $f['save'] .= "$this->action/$fid";
            }
            //if ($final == 'null') { throw new \Exception('At least two characters must be provided', 400); }
            return $final;
        }

        try {
            if (mb_strlen($q, 'UTF-8') < 2) {
                throw new \Exception('Query string too short');
            }
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
        $stmt = $this->mysqli->prepare($this->q);
        foreach ($final as &$f) {
            $fid = $f['regid']['val'];
            $obj = json_encode($f);
            $run = NULL;
            $stmt->bind_param("ssss", $this->action, $fid, $obj, $run);
            $stmt->execute();
            $f['save'] = $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_contacts_import_v1']['path'] . '/';
            $f['save'] .= "$this->action/$fid";
        }

        /*
        $sq = "SELECT bin_to_uuid(c_uuid, true), bin_to_uuid(c_action, true), c_fid FROM t_if__objects WHERE c_action = UUID_TO_BIN(?, true) AND c_fid IN (" . implode(',', array_fill(0, count($fids), '?')) . ")";
        $sstmt = $this->mysqli->prepare($sq);
        $sstmt->bind_param("s" . str_repeat("s", count($fids)), $act, ...$fids);
        $sstmt->execute();
        $sstmt->bind_result($uuid, $action, $fid);
        $result = $sstmt->get_result();
        $res = $result->fetch_all(MYSQLI_ASSOC);
        $sstmt->close();
        $this->fscache->set($key, $response, 3600);
        */

        //if ($final == 'null') { throw new \Exception('At least two characters must be provided', 400); }
        return $final;
    }


    function addIssKey(&$array) {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                addIssKey($value); // Recursively check nested arrays
            } else {
                if ($key === 'val') {
                    $array['iss'] = 'ares.gov.cz';
                }
            }
        }
    }

    private function map(&$i,$ip,&$o,$op) {
        if ($i->get($ip)) { $o->{$op} = $i->{$ip}[0]; }
    }
    public function act_r1(Request $request, Response $response, array $args = []): Response {
        $action = 'd65d2468-afe0-40c2-986c-e67047141013';
        $data = $this->fetch($args['q']);
        $res = [
            'results' => count($data),
            'data' => $data
        ];
        return $response->withJson($res);
    }



    public function docs_r1(Request $request, Response $response, array $args = []): Response {
        return $response->withJson($args);
    }




}
