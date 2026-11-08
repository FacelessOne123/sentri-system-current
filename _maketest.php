<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
$realm = $_GET['realm'] ?? 'gov';
$uid = (int)($_GET['uid'] ?? 0);
sentri_boot_realm($realm);
$s = $conn->prepare("SELECT first_name,last_name,role FROM users WHERE id=? LIMIT 1");
$s->bind_param("i", $uid); $s->execute();
$u = $s->get_result()->fetch_assoc(); $s->close();
if (!$u) { echo json_encode(['ok'=>false]); exit; }
$_SESSION['identity'] = [
 'user_id'=>$uid,'first_name'=>$u['first_name'],'last_name'=>$u['last_name'],
 'role'=>$u['role'],'is_approved'=>true,'email_verified'=>true,
];
$_SESSION['user_id']=$uid; $_SESSION['first_name']=$u['first_name']; $_SESSION['last_name']=$u['last_name'];
$_SESSION['role']=$u['role']; $_SESSION['is_approved']=true; $_SESSION['csrf_token']='testtoken';
session_write_close();
echo json_encode(['ok'=>true,'sid'=>session_id(),'realm'=>$realm,'uid'=>$uid,'role'=>$u['role']]);
