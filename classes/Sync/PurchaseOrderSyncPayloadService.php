<?php

require_once __DIR__ . '/BranchIdentity.php';

class PurchaseOrderSyncPayloadService
{
    private const ORDER_FIELDS = ['id','purchase_order_uuid','pos_tenant','pos_branch','branch_uuid','supplier_account_id','destination_store_id','status','expected_at','created_by','submitted_by','approved_by','closed_by','created_at','submitted_at','approved_at','closed_at','updated_at','notes','sync_revision'];
    private const LINE_FIELDS = ['id','purchase_order_id','item_id','unit_id','ordered_qty','received_qty','unit_cost','total_cost','notes','created_at','updated_at'];
    private const STATUSES = ['draft','submitted','approved','rejected','partially_received','received','closed','cancelled'];

    public function build(mysqli $conn, string $branchUuid, int $orderId): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!SyncBranchIdentity::isUuid($branchUuid) || $orderId < 1) throw new InvalidArgumentException('PURCHASE_ORDER_SYNC_IDENTITY_INVALID');
        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_orders WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$order) throw new RuntimeException('PURCHASE_ORDER_SYNC_ORDER_MISSING');
        $order = $this->select($order, self::ORDER_FIELDS);
        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_order_lines WHERE purchase_order_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $orderId); $stmt->execute(); $result = $stmt->get_result(); $lines = [];
        while ($line = $result->fetch_assoc()) $lines[] = $this->select($line, self::LINE_FIELDS);
        $stmt->close();
        $payload = ['schema_version'=>1,'snapshot_type'=>'purchase_order_bundle','domain'=>'purchase_order','branch_uuid'=>$branchUuid,'sync_revision'=>(int)$order['sync_revision'],'purchase_order'=>$order,'purchase_order_lines'=>$lines,'totals'=>['line_count'=>count($lines)]];
        $payload['payload_hash'] = hash('sha256', self::encodeJson($payload));
        $this->assertValid($payload, $branchUuid);
        return $payload;
    }

    public function assertValid(array $payload, string $branchUuid, array $event = []): void
    {
        $top = ['schema_version','snapshot_type','domain','branch_uuid','sync_revision','purchase_order','purchase_order_lines','totals','payload_hash'];
        $branchUuid = strtolower(trim($branchUuid));
        if (!$this->exact($payload,$top) || (int)($payload['schema_version']??0)!==1 || ($payload['snapshot_type']??'')!=='purchase_order_bundle' || ($payload['domain']??'')!=='purchase_order' || !SyncBranchIdentity::isUuid($branchUuid) || strtolower(trim((string)($payload['branch_uuid']??'')))!==$branchUuid || !is_array($payload['purchase_order']??null) || !is_array($payload['purchase_order_lines']??null) || !is_array($payload['totals']??null)) throw new RuntimeException('PURCHASE_ORDER_SYNC_PAYLOAD_INVALID');
        $copy=$payload; $expected=(string)$copy['payload_hash']; unset($copy['payload_hash']);
        if ($expected==='' || !hash_equals($expected,hash('sha256',self::encodeJson($copy)))) throw new RuntimeException('PURCHASE_ORDER_SYNC_HASH_INVALID');
        $order=$payload['purchase_order']; $this->assertFields($order,self::ORDER_FIELDS,'PURCHASE_ORDER_SYNC_ORDER_INVALID');
        $id=(int)($order['id']??0); $uuid=strtolower(trim((string)($order['purchase_order_uuid']??''))); $status=(string)($order['status']??''); $revision=(int)($order['sync_revision']??0);
        if ($id<1 || !SyncBranchIdentity::isUuid($uuid) || strtolower(trim((string)($order['branch_uuid']??'')))!==$branchUuid || (int)($order['pos_tenant']??-1)<0 || (int)($order['pos_branch']??-1)<0 || (int)($order['destination_store_id']??0)<1 || !in_array($status,self::STATUSES,true) || $revision<1 || (int)($payload['sync_revision']??0)!==$revision) throw new RuntimeException('PURCHASE_ORDER_SYNC_ORDER_INVALID');
        foreach(['supplier_account_id','created_by','submitted_by','approved_by','closed_by'] as $field) if(!$this->nullablePositiveInt($order[$field]??null)) throw new RuntimeException('PURCHASE_ORDER_SYNC_ORDER_INVALID');
        foreach(['expected_at','submitted_at','approved_at','closed_at'] as $field) if(!$this->dateTime($order[$field]??null,false)) throw new RuntimeException('PURCHASE_ORDER_SYNC_ORDER_INVALID');
        foreach(['created_at','updated_at'] as $field) if(!$this->dateTime($order[$field]??null,true)) throw new RuntimeException('PURCHASE_ORDER_SYNC_ORDER_INVALID');
        if ($order['submitted_by']!==null && !$this->dateTime($order['submitted_at']??null,true)) throw new RuntimeException('PURCHASE_ORDER_SYNC_LIFECYCLE_INVALID');
        if ($order['approved_by']!==null && !$this->dateTime($order['approved_at']??null,true)) throw new RuntimeException('PURCHASE_ORDER_SYNC_LIFECYCLE_INVALID');
        $this->text($order['notes']??null,65535,'PURCHASE_ORDER_SYNC_ORDER_INVALID');
        $ids=[]; $items=[]; $receivedAny=false; $fullyReceived=true;
        foreach($payload['purchase_order_lines'] as $line){
            if(!is_array($line)) throw new RuntimeException('PURCHASE_ORDER_SYNC_LINE_INVALID');
            $this->assertFields($line,self::LINE_FIELDS,'PURCHASE_ORDER_SYNC_LINE_INVALID'); $lineId=(int)($line['id']??0); $item=(int)($line['item_id']??0);
            if($lineId<1 || isset($ids[$lineId]) || $item<1 || isset($items[$item]) || (int)($line['purchase_order_id']??0)!==$id || !$this->nullablePositiveInt($line['unit_id']??null) || !$this->positive($line['ordered_qty']??null) || !$this->nonNegative($line['received_qty']??null) || bccomp((string)$line['received_qty'],(string)$line['ordered_qty'],8)>0 || !$this->nonNegative($line['unit_cost']??null) || !$this->nonNegative($line['total_cost']??null) || !$this->dateTime($line['created_at']??null,true) || !$this->dateTime($line['updated_at']??null,true)) throw new RuntimeException('PURCHASE_ORDER_SYNC_LINE_INVALID');
            $this->text($line['notes']??null,65535,'PURCHASE_ORDER_SYNC_LINE_INVALID'); $ids[$lineId]=true; $items[$item]=true;
            $receivedAny = $receivedAny || bccomp((string)$line['received_qty'],'0',8)>0; $fullyReceived = $fullyReceived && bccomp((string)$line['received_qty'],(string)$line['ordered_qty'],8)===0;
        }
        if($ids===[] || array_keys($payload['totals'])!==['line_count'] || (int)($payload['totals']['line_count']??-1)!==count($ids)) throw new RuntimeException('PURCHASE_ORDER_SYNC_TOTALS_INVALID');
        if(($status==='partially_received' && (!$receivedAny || $fullyReceived)) || ($status==='received' && !$fullyReceived) || (in_array($status,['draft','submitted','approved'],true) && $receivedAny)) throw new RuntimeException('PURCHASE_ORDER_SYNC_PROGRESS_INVALID');
        if($event!==[] && (($event['aggregate_type']??'')!=='purchase_order' || strtolower(trim((string)($event['aggregate_uuid']??'')))!==$uuid || (int)($event['aggregate_local_id']??0)!==$id || (int)($event['event_version']??0)!==$revision)) throw new RuntimeException('PURCHASE_ORDER_SYNC_EVENT_IDENTITY_INVALID');
    }

    public static function encodeJson(array $value): string { $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION); if(!is_string($json)) throw new RuntimeException('PURCHASE_ORDER_SYNC_JSON_INVALID'); return $json; }
    private function select(array $row,array $fields):array{$out=[];foreach($fields as $f){if(!array_key_exists($f,$row))throw new RuntimeException('PURCHASE_ORDER_SYNC_SCHEMA_REQUIRED');$out[$f]=$row[$f];}return $out;}
    private function exact(array $row,array $fields):bool{return array_diff(array_keys($row),$fields)===[]&&array_diff($fields,array_keys($row))===[];}
    private function assertFields(array $row,array $fields,string $code):void{if(!$this->exact($row,$fields))throw new RuntimeException($code);}
    private function nullablePositiveInt($v):bool{return $v===null||$v===''||(filter_var($v,FILTER_VALIDATE_INT)!==false&&(int)$v>0);}
    private function decimal($v):bool{return is_int($v)||is_float($v)||(is_string($v)&&preg_match('/^-?\d+(?:\.\d+)?$/',$v)===1);}
    private function positive($v):bool{return $this->decimal($v)&&bccomp((string)$v,'0',8)>0;}
    private function nonNegative($v):bool{return $this->decimal($v)&&bccomp((string)$v,'0',8)>=0;}
    private function dateTime($v,bool $required):bool{if($v===null||$v==='')return !$required;return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',$v)===1;}
    private function text($v,int $max,string $code):void{if($v!==null&&(!is_string($v)||strlen($v)>$max))throw new RuntimeException($code);}
}
