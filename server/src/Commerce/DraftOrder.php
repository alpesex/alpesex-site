<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Commerce;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class DraftOrder
{
    private const OFFERS = [
        'trial' => ['name' => 'Essai', 'price' => 0, 'included' => ['master'=>1,'user'=>1,'manager'=>1,'direction'=>1]],
        'discovery' => ['name' => 'Découverte', 'price' => 16600, 'included' => ['master'=>1,'user'=>3,'manager'=>1,'direction'=>1]],
        'pro' => ['name' => 'Pro', 'price' => 45900, 'included' => ['master'=>1,'user'=>20,'manager'=>1,'direction'=>1]],
    ];
    private const ADDONS = [
        'user' => ['name'=>'Licence Utilisateur supplémentaire','price'=>1900,'units'=>1],
        'userPack5' => ['name'=>'Lot de 5 licences Utilisateur','price'=>9000,'units'=>5],
        'userPack20' => ['name'=>'Lot de 20 licences Utilisateur','price'=>34200,'units'=>20],
        'manager' => ['name'=>'Licence Manager supplémentaire','price'=>3900,'units'=>1],
        'direction' => ['name'=>'Licence Direction supplémentaire','price'=>2900,'units'=>1],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $input */
    public function create(int $organizationId, int $userId, array $input): string
    {
        $offerKey=(string)($input['offerKey']??'');
        if(!isset(self::OFFERS[$offerKey])) throw new RuntimeException('Offre invalide.');
        $extras=is_array($input['extras']??null)?$input['extras']:[];
        $items=[];$subtotal=self::OFFERS[$offerKey]['price'];
        $items[]=['key'=>'offer_'.$offerKey,'description'=>'Offre '.self::OFFERS[$offerKey]['name'],'quantity'=>1,'unit'=>$subtotal,'total'=>$subtotal,'metadata'=>['included'=>self::OFFERS[$offerKey]['included']]];
        foreach(self::ADDONS as $key=>$definition){
            $quantity=filter_var($extras[$key]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999]]);
            if($quantity===false) throw new RuntimeException('Quantité invalide : '.$key);
            if($quantity===0) continue;
            $total=$quantity*$definition['price'];$subtotal+=$total;
            $items[]=['key'=>$key,'description'=>$definition['name'],'quantity'=>$quantity,'unit'=>$definition['price'],'total'=>$total,'metadata'=>['licenses_per_unit'=>$definition['units']]];
        }
        $publicId=bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try{
            $order=$this->pdo->prepare('INSERT INTO orders (public_id,organization_id,created_by_user_id,status,offer_key,subtotal_cents,tax_cents,total_cents,currency,expires_at) VALUES (:public_id,:organization_id,:user_id,\'draft\',:offer_key,:subtotal,0,:total,\'EUR\',:expires_at)');
            $order->execute(['public_id'=>$publicId,'organization_id'=>$organizationId,'user_id'=>$userId,'offer_key'=>$offerKey,'subtotal'=>$subtotal,'total'=>$subtotal,'expires_at'=>(new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s')]);
            $orderId=(int)$this->pdo->lastInsertId();
            $itemStatement=$this->pdo->prepare('INSERT INTO order_items (order_id,item_key,description,quantity,unit_price_cents,total_cents,metadata_json) VALUES (:order_id,:item_key,:description,:quantity,:unit_price,:total,:metadata)');
            foreach($items as $item)$itemStatement->execute(['order_id'=>$orderId,'item_key'=>$item['key'],'description'=>$item['description'],'quantity'=>$item['quantity'],'unit_price'=>$item['unit'],'total'=>$item['total'],'metadata'=>json_encode($item['metadata'],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();return $publicId;
        }catch(\Throwable $exception){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $exception;}
    }

    /** @return array<string,mixed> */
    public function get(int $organizationId, string $publicId): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/',$publicId)) throw new RuntimeException('Commande invalide.');
        $order=$this->pdo->prepare('SELECT public_id,status,offer_key,subtotal_cents,tax_cents,total_cents,currency,expires_at,created_at FROM orders WHERE public_id=:public_id AND organization_id=:organization_id LIMIT 1');
        $order->execute(['public_id'=>$publicId,'organization_id'=>$organizationId]);$record=$order->fetch();
        if(!is_array($record)) throw new RuntimeException('Commande introuvable.');
        $items=$this->pdo->prepare('SELECT item_key,description,quantity,unit_price_cents,total_cents,metadata_json FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id WHERE o.public_id=:public_id AND o.organization_id=:organization_id ORDER BY oi.id');
        $items->execute(['public_id'=>$publicId,'organization_id'=>$organizationId]);
        return ['id'=>$record['public_id'],'status'=>$record['status'],'offerKey'=>$record['offer_key'],'subtotalCents'=>(int)$record['subtotal_cents'],'taxCents'=>(int)$record['tax_cents'],'totalCents'=>(int)$record['total_cents'],'currency'=>$record['currency'],'expiresAt'=>$record['expires_at'],'createdAt'=>$record['created_at'],'items'=>array_map(static fn(array $i):array=>['key'=>$i['item_key'],'description'=>$i['description'],'quantity'=>(int)$i['quantity'],'unitPriceCents'=>(int)$i['unit_price_cents'],'totalCents'=>(int)$i['total_cents'],'metadata'=>json_decode($i['metadata_json']??'{}',true)],$items->fetchAll())];
    }
}
