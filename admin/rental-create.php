<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) { flash('danger','Administrator access is required.'); redirect('index.php'); }
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$editing=$id>0;
$adminPageTitle=$editing?'Edit Rental':'New Rental';
$existing=null; $existingItems=[]; $existingDates=[]; $hasLockedHistory=false;
if($editing){
    $stmt=db()->prepare('SELECT * FROM rentals WHERE id=?'); $stmt->execute([$id]); $existing=$stmt->fetch();
    if(!$existing){ flash('danger','Rental not found.'); redirect('rentals.php'); }
    $existingItems=rental_items_for($id);
    if(($existing['schedule_type']??'continuous')==='flexible') $existingDates=array_map(static fn($r)=>(string)$r['rental_date'],rental_dates_for($id,false));
    $q=db()->prepare('SELECT (SELECT COUNT(*) FROM rental_extensions WHERE rental_id=?) + (SELECT COUNT(*) FROM rental_billing_periods WHERE rental_id=? AND finalized_at IS NOT NULL) + (SELECT COUNT(*) FROM rental_charges WHERE rental_id=?)'); $q->execute([$id,$id,$id]);
    $hasLockedHistory=(int)$q->fetchColumn()>0 || !empty($existing['early_ended_at']) || !empty($existing['final_billed_at']);
    if($hasLockedHistory){ flash('warning','This rental already has extension, charge, or final-billing history and can no longer be edited from the standard form.'); redirect('rental-view.php?id='.$id); }
}
$rentables=db()->query("SELECT x.*,c.name AS category_name FROM rentables x JOIN rental_categories c ON c.id=x.category_id WHERE x.is_active=1".($editing?" OR x.id IN (SELECT rentable_id FROM rental_items WHERE rental_id=".$id.")":"")." ORDER BY c.sort_order,c.name,x.code")->fetchAll();
$rentalOptions=[]; foreach($rentables as $x){ $rentalOptions[(int)$x['id']]=$x; }
$form=[
 'client_name'=>(string)($existing['client_name']??''),'contact_number'=>(string)($existing['contact_number']??''),'organization'=>(string)($existing['organization']??''),
 'schedule_type'=>(string)($existing['schedule_type']??'continuous'),'start_date'=>(string)($existing['start_date']??date('Y-m-d')),'end_date'=>(string)($existing['end_date']??date('Y-m-d')),
 'is_complimentary'=>(int)($existing['is_complimentary']??0),'complimentary_reason'=>(string)($existing['complimentary_reason']??''),'security_deposit_required'=>number_format((float)($existing['security_deposit_required']??0),2,'.',''),'notes'=>(string)($existing['notes']??''),
 'initial_payment'=>'','payment_method'=>'Cash','payment_reference'=>'','paid_at'=>date('Y-m-d\TH:i')
];
$itemRows=[];
if($existingItems){ foreach($existingItems as $row) $itemRows[]=['rentable_id'=>(int)$row['rentable_id'],'quantity'=>(int)$row['quantity'],'agreed_amount'=>number_format((float)$row['agreed_amount'],2,'.','')]; }
if(!$itemRows) $itemRows[]=['rentable_id'=>0,'quantity'=>1,'agreed_amount'=>''];
$flexibleDates=$existingDates; $recordInitialPayment=false; $errors=[];
$validDate=static function(string $v):bool{ $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); return $d!==false&&$d->format('Y-m-d')===$v; };

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 foreach(['client_name','contact_number','organization','schedule_type','start_date','end_date','complimentary_reason','security_deposit_required','notes','initial_payment','payment_method','payment_reference','paid_at'] as $key){ if(isset($_POST[$key])&&!is_array($_POST[$key])) $form[$key]=trim((string)$_POST[$key]); }
 $isComplimentary=isset($_POST['is_complimentary']); $form['is_complimentary']=$isComplimentary?1:0;
 $recordInitialPayment=!$editing&&!$isComplimentary&&isset($_POST['record_initial_payment']);
 if(!$recordInitialPayment){ $form['initial_payment']=''; $form['payment_reference']=''; }
 $itemRows=[]; $seenRentables=[]; $ids=(array)($_POST['rentable_id']??[]); $qtys=(array)($_POST['quantity']??[]); $amounts=(array)($_POST['agreed_amount']??[]);
 $requestedItems=[]; $billingModes=[]; $allFlexible=true; $anyUtilities=false; $baseMonthlyOrOneTime=0.0;
 foreach($ids as $i=>$rawId){
    $rid=(int)$rawId; if($rid<=0) continue;
    $qty=max(1,(int)($qtys[$i]??1)); $amtRaw=trim((string)($amounts[$i]??''));
    $itemRows[]=['rentable_id'=>$rid,'quantity'=>$qty,'agreed_amount'=>$isComplimentary?'0.00':$amtRaw];
    if(isset($seenRentables[$rid])) { $errors[]='Add each rentable only once. Use Quantity when more than one unit is needed.'; continue; }
    $seenRentables[$rid]=true;
    $x=$rentalOptions[$rid]??null;
    if(!$x){ $errors[]='One selected rentable is unavailable.'; continue; }
    if($x['availability_mode']==='exclusive') $qty=1;
    if($qty>max(1,(int)$x['total_quantity'])) $errors[]=$x['code'].' only has '.(int)$x['total_quantity'].' unit(s) configured.';
    $amount=$isComplimentary?0.0:(is_numeric($amtRaw)?round((float)$amtRaw,2):-1);
    if(!$isComplimentary&&$amount<=0) $errors[]='Enter the agreed amount for '.$x['code'].'. No default price is applied.';
    $requestedItems[]=['rentable_id'=>$rid,'quantity'=>$qty,'agreed_amount'=>max(0,$amount)];
    $billingModes[(string)$x['billing_mode']]=true; $allFlexible=$allFlexible&&(int)$x['allow_flexible_dates']===1; $anyUtilities=$anyUtilities||(int)$x['allow_utilities']===1;
    $baseMonthlyOrOneTime+=max(0,$amount);
 }
 if(!$itemRows){ $itemRows[]=['rentable_id'=>0,'quantity'=>1,'agreed_amount'=>'']; $errors[]='Add at least one rentable.'; }
 if($form['client_name']==='') $errors[]='Enter the client / tenant name.';
 if(count($billingModes)>1) $errors[]='All rentables in one rental must use the same billing mode. Create separate rental records for items with different billing modes.';
 $billingMode=array_key_first($billingModes)?:'one_time';
 $scheduleType=in_array($form['schedule_type'],['continuous','flexible'],true)?$form['schedule_type']:'continuous';
 if($billingMode==='monthly') $scheduleType='continuous';
 if($scheduleType==='flexible'&&!$allFlexible) $errors[]='One or more selected rentables do not allow flexible dates.';
 $flexibleDates=[]; foreach((array)($_POST['flexible_dates']??[]) as $v){ $v=trim((string)$v); if($validDate($v)) $flexibleDates[$v]=$v; } ksort($flexibleDates); $flexibleDates=array_values($flexibleDates);
 $start=$form['start_date']; $end=$form['end_date'];
 if($scheduleType==='flexible'){
    if(!$flexibleDates) $errors[]='Add at least one flexible rental date.';
    else { $start=$flexibleDates[0]; $end=$flexibleDates[count($flexibleDates)-1]; $form['start_date']=$start; $form['end_date']=$end; }
 } else {
    if(!$validDate($start)||!$validDate($end)) $errors[]='Enter valid rental dates.'; elseif($end<$start) $errors[]='Rental end date cannot be before the start date.';
 }
 if($isComplimentary){ if(trim($form['complimentary_reason'])==='') $errors[]='Enter the reason for the complimentary rental.'; }
 $securityDeposit=is_numeric($form['security_deposit_required'])?round((float)$form['security_deposit_required'],2):-1;
 if($securityDeposit<0) $errors[]='Security Deposit cannot be negative.';
 if($editing&&$securityDeposit+0.001<rental_deposit_held($id)) $errors[]='Security Deposit cannot be lower than the amount currently held. Refund the excess deposit first.';
 $initial=$form['initial_payment']===''?0.0:(is_numeric($form['initial_payment'])?round((float)$form['initial_payment'],2):-1);
 if($recordInitialPayment&&$initial<=0) $errors[]='Enter the initial payment amount.';
 if($initial<0) $errors[]='Enter a valid initial payment amount.';
 if(!$editing&&$recordInitialPayment&&$initial>0){ if(!in_array($form['payment_method'],booking_payment_methods(),true)) $errors[]='Choose a valid payment method.'; if($form['payment_method']!=='Cash'&&$form['payment_reference']==='') $errors[]='Enter a transaction reference or OR number for non-cash payments.'; if(!strtotime($form['paid_at'])) $errors[]='Enter a valid initial payment date and time.'; }
 if(!$errors) $errors=array_merge($errors,rental_availability_errors($requestedItems,$scheduleType,$start,$end,$flexibleDates,$editing?$id:0));
 if(!$errors){
  $availabilityLockHeld=false; $recordLockHeld=false;
  try{
   $pdo=db();
   if(!rental_availability_lock_acquire(10)) throw new RuntimeException('Another rental update is being processed. Please try again in a few seconds.');
   $availabilityLockHeld=true;
   if($editing){
     if(!rental_record_lock_acquire($id,10)) throw new RuntimeException('This rental is being updated by another administrator. Please try again.');
     $recordLockHeld=true;
   }
   $lockedAvailabilityErrors=rental_availability_errors($requestedItems,$scheduleType,$start,$end,$flexibleDates,$editing?$id:0);
   if($lockedAvailabilityErrors) throw new RuntimeException(implode(' ',array_unique($lockedAvailabilityErrors)));
   $pdo->beginTransaction();
   if($editing){
     $rowLock=$pdo->prepare('SELECT id FROM rentals WHERE id=? FOR UPDATE'); $rowLock->execute([$id]);
     if(!(int)$rowLock->fetchColumn()) throw new RuntimeException('Rental not found.');
     $wasComplimentary=(int)$existing['is_complimentary']===1; if($wasComplimentary!==$isComplimentary&&rental_payment_total($id)>0.009) throw new RuntimeException('Complimentary status cannot be changed after payments have been recorded.');
     $compBy=$isComplimentary?($wasComplimentary&&!empty($existing['complimentary_by'])?(int)$existing['complimentary_by']:(int)current_admin()['id']):null;
     $compAt=$isComplimentary?($wasComplimentary&&!empty($existing['complimentary_at'])?(string)$existing['complimentary_at']:date('Y-m-d H:i:s')):null;
     $stmt=$pdo->prepare('UPDATE rentals SET client_name=?,contact_number=?,organization=?,schedule_type=?,billing_mode=?,start_date=?,end_date=?,is_complimentary=?,complimentary_reason=?,complimentary_by=?,complimentary_at=?,security_deposit_required=?,notes=? WHERE id=?');
     $stmt->execute([$form['client_name'],$form['contact_number']?:null,$form['organization']?:null,$scheduleType,$billingMode,$start,$end,$isComplimentary?1:0,$isComplimentary?$form['complimentary_reason']:null,$compBy,$compAt,$securityDeposit,$form['notes']?:null,$id]);
     $pdo->prepare('DELETE FROM rental_items WHERE rental_id=?')->execute([$id]); $pdo->prepare('DELETE FROM rental_dates WHERE rental_id=?')->execute([$id]);
     $ins=$pdo->prepare('INSERT INTO rental_items(rental_id,rentable_id,quantity,agreed_amount) VALUES(?,?,?,?)'); foreach($requestedItems as $it) $ins->execute([$id,$it['rentable_id'],$it['quantity'],$it['agreed_amount']]);
     if($scheduleType==='flexible'){ $di=$pdo->prepare("INSERT INTO rental_dates(rental_id,rental_date,status) VALUES(?,?,'scheduled')"); foreach($flexibleDates as $d) $di->execute([$id,$d]); }
     if($billingMode==='monthly') rental_generate_monthly_periods($id,$start,$end,$baseMonthlyOrOneTime); else $pdo->prepare('DELETE FROM rental_billing_periods WHERE rental_id=?')->execute([$id]);
     rental_recalculate_header($id); $paid=rental_payment_total($id); $rr=$pdo->prepare('SELECT * FROM rentals WHERE id=?'); $rr->execute([$id]); $new=$rr->fetch(); if(rental_total_amount($new)+0.001<$paid) throw new RuntimeException('The revised rental total cannot be lower than the amount already paid.');
     $pdo->commit();
     if($recordLockHeld){ rental_record_lock_release($id); $recordLockHeld=false; }
     if($availabilityLockHeld){ rental_availability_lock_release(); $availabilityLockHeld=false; }
     flash('success','Rental updated.'); redirect('rental-view.php?id='.$id);
   } else {
     $ref=rental_reference(); $compBy=$isComplimentary?(int)current_admin()['id']:null; $compAt=$isComplimentary?date('Y-m-d H:i:s'):null;
     $stmt=$pdo->prepare("INSERT INTO rentals(reference_no,client_name,contact_number,organization,schedule_type,billing_mode,start_date,end_date,base_amount,amount_paid,payment_status,security_deposit_required,status,is_complimentary,complimentary_reason,complimentary_by,complimentary_at,notes,created_by) VALUES(?,?,?,?,?,?,?,?,0,0,'unpaid',?,'active',?,?,?,?,?,?)");
     $stmt->execute([$ref,$form['client_name'],$form['contact_number']?:null,$form['organization']?:null,$scheduleType,$billingMode,$start,$end,$securityDeposit,$isComplimentary?1:0,$isComplimentary?$form['complimentary_reason']:null,$compBy,$compAt,$form['notes']?:null,current_admin()['id']]);
     $newId=(int)$pdo->lastInsertId(); $ins=$pdo->prepare('INSERT INTO rental_items(rental_id,rentable_id,quantity,agreed_amount) VALUES(?,?,?,?)'); foreach($requestedItems as $it) $ins->execute([$newId,$it['rentable_id'],$it['quantity'],$it['agreed_amount']]);
     if($scheduleType==='flexible'){ $di=$pdo->prepare("INSERT INTO rental_dates(rental_id,rental_date,status) VALUES(?,?,'scheduled')"); foreach($flexibleDates as $d) $di->execute([$newId,$d]); }
     if($billingMode==='monthly') rental_generate_monthly_periods($newId,$start,$end,$baseMonthlyOrOneTime);
     rental_recalculate_header($newId); $head=$pdo->prepare('SELECT * FROM rentals WHERE id=?'); $head->execute([$newId]); $created=$head->fetch(); $target=rental_total_amount($created);
     if($initial>$target+0.001) throw new RuntimeException('Initial payment cannot exceed the current rental total of '.money($target).'.');
     if($recordInitialPayment&&$initial>0){ $p=$pdo->prepare('INSERT INTO rental_payments(rental_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)'); $p->execute([$newId,$initial,$form['payment_method'],$form['payment_reference']?:null,'Initial rental payment',current_admin()['id'],date('Y-m-d H:i:s',strtotime($form['paid_at']))]); rental_recalculate_header($newId); }
     $pdo->commit();
     if($availabilityLockHeld){ rental_availability_lock_release(); $availabilityLockHeld=false; }
     flash('success','Rental created.'); redirect('rental-view.php?id='.$newId);
   }
  } catch(Throwable $e){
   if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
   if($recordLockHeld) rental_record_lock_release($id);
   if($availabilityLockHeld) rental_availability_lock_release();
   $errors[]=$e instanceof RuntimeException?$e->getMessage():'Unable to save the rental.';
  }
 }
}
$isComplimentary=(int)$form['is_complimentary']===1;
$rentablePayload=[]; foreach($rentables as $x){ $rentablePayload[]=['id'=>(int)$x['id'],'code'=>$x['code'],'name'=>$x['name'],'category'=>$x['category_name'],'availability'=>$x['availability_mode'],'total'=>(int)$x['total_quantity'],'billing'=>$x['billing_mode'],'flexible'=>(int)$x['allow_flexible_dates'],'utilities'=>(int)$x['allow_utilities']]; }
include __DIR__ . '/_header.php';
?>
<section class="panel rental-create-panel">
<div class="panel-head rental-create-head"><div><h2><?= $editing?'Edit Rental':'New Rental' ?></h2><p class="muted">Choose the client, rentable, schedule, and agreed charge. Additional options stay out of the way until needed.</p></div><div class="admin-actions"><a class="btn btn-outline btn-sm" href="rentals.php">Rental List</a><a class="btn btn-outline btn-sm" href="rentables.php">Manage Rentables</a></div></div>
<?php if($errors): ?><div class="alert alert-danger"><strong>Please fix the following:</strong><ul><?php foreach($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" data-generic-rental-form><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">

<section class="rental-form-section rental-form-section-compact">
  <div class="rental-form-section-head compact"><div><h3>Client</h3></div></div>
  <div class="rental-client-grid">
    <div class="form-group"><label>Client / Tenant *</label><input name="client_name" maxlength="160" value="<?= e($form['client_name']) ?>" required></div>
    <div class="form-group"><label>Contact Number</label><input name="contact_number" maxlength="50" value="<?= e($form['contact_number']) ?>"></div>
    <div class="form-group"><label>Organization / Company</label><input name="organization" maxlength="160" value="<?= e($form['organization']) ?>"></div>
  </div>
</section>

<section class="rental-form-section rental-form-section-compact">
  <div class="rental-form-section-head compact"><div><h3>Rentables</h3><p>Select the space or item and enter the agreed charge. Add more only when needed.</p></div><div class="rental-base-total"><span data-base-label>Base total</span><strong data-base-summary>₱0.00</strong></div></div>
  <div class="generic-rental-items" data-rental-items></div>
  <div class="rental-item-actions"><button class="btn btn-outline btn-sm" type="button" data-add-rental-item>+ Add Another Rentable</button></div>
  <div class="rental-compatibility-warning" data-billing-warning hidden>Selected rentables use different billing modes. Create separate rental transactions for these items.</div>
  <div class="rental-complimentary-row">
    <label class="rental-compact-check"><input type="checkbox" name="is_complimentary" value="1" data-complimentary-toggle<?= $isComplimentary?' checked':'' ?>><span><strong>Complimentary / Free</strong><small>For record purposes only</small></span></label>
    <div class="form-group rental-complimentary-reason" data-complimentary-reason<?= $isComplimentary?'':' hidden' ?>><label>Reason *</label><input name="complimentary_reason" maxlength="1000" value="<?= e($form['complimentary_reason']) ?>" placeholder="Why is this rental complimentary?"></div>
  </div>
</section>

<section class="rental-form-section rental-form-section-compact">
  <div class="rental-form-section-head compact"><div><h3>Schedule</h3><p data-schedule-help>Choose continuous dates or selected individual dates.</p></div></div>
  <div class="rental-schedule-switch" data-schedule-switch><label><input type="radio" name="schedule_type" value="continuous"<?= $form['schedule_type']!=='flexible'?' checked':'' ?>><span>Continuous</span></label><label><input type="radio" name="schedule_type" value="flexible"<?= $form['schedule_type']==='flexible'?' checked':'' ?>><span>Flexible Dates</span></label></div>
  <div class="form-grid rental-date-grid" data-continuous-dates><div class="form-group"><label data-start-date-label>Start Date *</label><input type="date" name="start_date" value="<?= e($form['start_date']) ?>"></div><div class="form-group"><label data-end-date-label>End Date *</label><input type="date" name="end_date" value="<?= e($form['end_date']) ?>"></div></div>
  <div data-flexible-dates hidden><div class="rental-flexible-toolbar"><div><strong>Selected Dates</strong><span class="field-help">Only these dates will be occupied.</span></div><button type="button" class="btn btn-outline btn-sm" data-add-flex-date>+ Add Date</button></div><div data-flex-date-list></div><div class="rental-flexible-empty" data-flex-empty>No dates added yet.</div></div>
</section>

<?php if(!$editing): ?>
<section class="rental-form-section rental-form-section-compact" data-payment-section>
  <div class="rental-payment-inline">
    <div><h3>Initial Payment</h3><p>Optional. Record it now only if money has already been received.</p></div>
    <label class="rental-compact-check"><input type="checkbox" name="record_initial_payment" value="1" data-payment-toggle<?= $recordInitialPayment?' checked':'' ?>><span><strong>Record payment now</strong></span></label>
  </div>
  <div class="form-grid rental-payment-fields" data-payment-fields<?= $recordInitialPayment?'':' hidden' ?>><div class="form-group"><label>Amount Paid *</label><input type="number" min="0.01" step="0.01" name="initial_payment" value="<?= e($form['initial_payment']) ?>"></div><div class="form-group"><label>Payment Method *</label><select name="payment_method" data-booking-payment-method><?php foreach(booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"<?= $form['payment_method']===$method?' selected':'' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Reference / OR</label><input name="payment_reference" maxlength="120" value="<?= e($form['payment_reference']) ?>" data-booking-payment-reference data-allow-cash-reference></div><div class="form-group"><label>Paid At *</label><input type="datetime-local" name="paid_at" value="<?= e($form['paid_at']) ?>"></div></div>
</section>
<?php endif; ?>

<details class="rental-more-options"<?= ((float)$form['security_deposit_required']>0 || trim($form['notes'])!=='')?' open':'' ?>>
  <summary><span>More Options</span><small>Security deposit and notes</small></summary>
  <div class="rental-more-options-body">
    <div class="rental-deposit-control">
      <label class="rental-compact-check"><input type="checkbox" data-deposit-toggle<?= (float)$form['security_deposit_required']>0?' checked':'' ?>><span><strong>Require Security Deposit</strong><small>Refundable and excluded from Sales</small></span></label>
      <div class="form-group" data-deposit-field<?= (float)$form['security_deposit_required']>0?'':' hidden' ?>><label>Security Deposit</label><input type="number" name="security_deposit_required" min="0" step="0.01" value="<?= e($form['security_deposit_required']) ?>"></div>
    </div>
    <div class="form-group"><label>Notes</label><textarea name="notes" placeholder="Optional rental notes"><?= e($form['notes']) ?></textarea></div>
  </div>
</details>

<div class="rental-form-footer"><?php if($editing): ?><a class="btn btn-outline" href="rental-view.php?id=<?= $id ?>">Cancel</a><?php endif; ?><button class="btn btn-primary" type="submit"><?= $editing?'Save Changes':'Create Rental' ?></button></div>
</form></section>

<template id="rental-item-template"><div class="generic-rental-item" data-item-row><div class="form-group generic-rental-select"><label>Rentable *</label><select name="rentable_id[]" data-item-select required><option value="">Select rentable</option><?php foreach($rentables as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['category_name'].' · '.$x['code'].' · '.$x['name']) ?></option><?php endforeach; ?></select></div><div class="form-group generic-rental-qty" data-qty-group><label>Qty *</label><input type="number" name="quantity[]" min="1" value="1" data-item-qty required></div><div class="form-group generic-rental-amount"><label data-amount-label>Agreed Charge *</label><input type="number" name="agreed_amount[]" min="0.01" step="0.01" placeholder="0.00" data-item-amount required></div><button type="button" class="btn btn-outline btn-sm generic-rental-remove" data-remove-item aria-label="Remove rentable">Remove</button><div class="generic-rental-item-meta" data-item-meta></div></div></template>
<script>
(function(){
var root=document.querySelector('[data-generic-rental-form]'); if(!root)return;
var catalog=<?= json_encode($rentablePayload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>, byId={}; catalog.forEach(function(x){byId[String(x.id)]=x;});
var initial=<?= json_encode($itemRows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>, dates=<?= json_encode(array_values($flexibleDates)) ?>;
var list=root.querySelector('[data-rental-items]'), template=document.getElementById('rental-item-template'), complimentary=root.querySelector('[data-complimentary-toggle]'), reason=root.querySelector('[data-complimentary-reason]'), billingWarning=root.querySelector('[data-billing-warning]'), baseSummary=root.querySelector('[data-base-summary]'), baseLabel=root.querySelector('[data-base-label]');
var continuous=root.querySelector('[data-continuous-dates]'), flexible=root.querySelector('[data-flexible-dates]'), dateList=root.querySelector('[data-flex-date-list]'), empty=root.querySelector('[data-flex-empty]'), startLabel=root.querySelector('[data-start-date-label]'), endLabel=root.querySelector('[data-end-date-label]'), scheduleHelp=root.querySelector('[data-schedule-help]');
var paymentSection=root.querySelector('[data-payment-section]'), paymentToggle=root.querySelector('[data-payment-toggle]'), paymentFields=root.querySelector('[data-payment-fields]'), depositToggle=root.querySelector('[data-deposit-toggle]'), depositField=root.querySelector('[data-deposit-field]'), depositInput=root.querySelector('input[name="security_deposit_required"]');
function money(v){return '₱'+Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function addItem(data){var frag=template.content.cloneNode(true), row=frag.querySelector('[data-item-row]'), sel=row.querySelector('[data-item-select]'), qty=row.querySelector('[data-item-qty]'), amt=row.querySelector('[data-item-amount]'); if(data){sel.value=String(data.rentable_id||'');qty.value=data.quantity||1;amt.value=data.agreed_amount||'';} row.querySelector('[data-remove-item]').addEventListener('click',function(){if(list.querySelectorAll('[data-item-row]').length>1){row.remove();sync();}}); sel.addEventListener('change',function(){syncRow(row);sync();}); qty.addEventListener('input',sync); amt.addEventListener('input',sync); list.appendChild(frag); syncRow(row);}
function syncRow(row){var x=byId[row.querySelector('[data-item-select]').value], qty=row.querySelector('[data-item-qty]'), qtyGroup=row.querySelector('[data-qty-group]'), meta=row.querySelector('[data-item-meta]'), label=row.querySelector('[data-amount-label]'); if(!x){meta.textContent='';qtyGroup.hidden=false;return;} var exclusive=x.availability==='exclusive'; row.classList.toggle('is-exclusive',exclusive); qtyGroup.hidden=exclusive; if(exclusive){qty.value=1;qty.max=1;qty.readOnly=true;}else{qty.readOnly=false;qty.max=x.total;} label.textContent=x.billing==='monthly'?'Monthly Amount *':'Agreed Charge *'; var parts=[]; parts.push(x.availability==='quantity'?x.total+' units available':'Exclusive'); parts.push(x.billing==='monthly'?'Monthly':'One-time'); if(x.flexible)parts.push('Flexible dates'); if(x.utilities)parts.push('Utilities'); meta.textContent=parts.join(' · ');}
function sync(){var rows=[].slice.call(list.querySelectorAll('[data-item-row]')), modes={}, allFlex=true,total=0, selected=0; rows.forEach(function(r){var x=byId[r.querySelector('[data-item-select]').value]; if(!x)return;selected++;modes[x.billing]=true;if(!x.flexible)allFlex=false;total+=Number(r.querySelector('[data-item-amount]').value||0);}); var mode=Object.keys(modes).length===1?Object.keys(modes)[0]:(Object.keys(modes).length>1?'mixed':''); billingWarning.hidden=mode!=='mixed'; baseLabel.textContent=mode==='monthly'?'Monthly base':'Base total'; baseSummary.textContent=complimentary.checked?money(0):money(total); var flexRadio=root.querySelector('input[name="schedule_type"][value="flexible"]'); if(mode==='monthly'||(selected>0&&!allFlex)){flexRadio.disabled=true;if(flexRadio.checked)root.querySelector('input[name="schedule_type"][value="continuous"]').checked=true;}else flexRadio.disabled=false; if(startLabel)startLabel.textContent=mode==='monthly'?'Lease Start *':'Start Date *'; if(endLabel)endLabel.textContent=mode==='monthly'?'Lease End *':'End Date *'; if(scheduleHelp)scheduleHelp.textContent=mode==='monthly'?'Monthly rentals use one continuous lease period.':'Choose continuous dates or selected individual dates.'; syncSchedule(); rows.forEach(function(r){var a=r.querySelector('[data-item-amount]'); if(complimentary.checked){if(!a.disabled)a.dataset.previous=a.value;a.value='0.00';a.disabled=true;}else if(a.disabled){a.disabled=false;if(a.dataset.previous!==undefined){a.value=a.dataset.previous;delete a.dataset.previous;}}}); reason.hidden=!complimentary.checked; if(paymentSection){paymentSection.hidden=complimentary.checked;if(complimentary.checked&&paymentToggle){paymentToggle.checked=false;if(paymentFields)paymentFields.hidden=true;}}}
function syncSchedule(){var checked=root.querySelector('input[name="schedule_type"]:checked'); var isFlex=checked&&checked.value==='flexible'&&!checked.disabled; continuous.hidden=isFlex; flexible.hidden=!isFlex;}
function addDate(value){var row=document.createElement('div');row.className='rental-flexible-row';row.innerHTML='<input type="date" name="flexible_dates[]" required><button type="button" class="btn btn-outline btn-sm">Remove</button>';var input=row.querySelector('input');if(value)input.value=value;row.querySelector('button').addEventListener('click',function(){row.remove();empty.hidden=dateList.children.length>0;});dateList.appendChild(row);empty.hidden=true;if(!value){setTimeout(function(){input.focus();if(typeof input.showPicker==='function'){try{input.showPicker();}catch(e){}}},0);}}
root.querySelector('[data-add-rental-item]').addEventListener('click',function(){addItem();sync();}); root.querySelector('[data-add-flex-date]').addEventListener('click',function(){addDate('');}); root.querySelectorAll('input[name="schedule_type"]').forEach(function(r){r.addEventListener('change',syncSchedule);}); complimentary.addEventListener('change',sync);
if(paymentToggle)paymentToggle.addEventListener('change',function(){if(paymentFields)paymentFields.hidden=!paymentToggle.checked;});
if(depositToggle)depositToggle.addEventListener('change',function(){depositField.hidden=!depositToggle.checked;if(!depositToggle.checked)depositInput.value='0.00';else if(Number(depositInput.value||0)===0)depositInput.value='';});
initial.forEach(addItem); dates.forEach(addDate); if(!dates.length)empty.hidden=false; sync();
root.addEventListener('submit',function(){root.querySelectorAll('[data-item-amount]:disabled').forEach(function(i){i.disabled=false;i.value='0.00';}); if(depositToggle&&!depositToggle.checked)depositInput.value='0.00';});
}());
</script>
<?php include __DIR__ . '/_footer.php'; ?>
