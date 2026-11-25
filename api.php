<?php
/* Danxomè : L'Héritage des Rois — API des profils légers, classements,
   sauvegardes et statistiques anonymes. © 2026 Blessmax Digital.
   Données minimales : pseudo + ville choisis par le joueur. Aucun e-mail,
   téléphone ni mot de passe. L'adresse IP n'est jamais stockée en clair. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// CORS : autorise le jeu servi depuis jeudanxome.com (GitHub Pages)
$o=$_SERVER['HTTP_ORIGIN']??'';if(in_array($o,['https://jeudanxome.com','https://www.jeudanxome.com'],true)){header('Access-Control-Allow-Origin: '.$o);header('Vary: Origin');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');header('Access-Control-Allow-Headers: Content-Type');header('Access-Control-Max-Age: 86400');}
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
// Fichier de vérification Android (Digital Asset Links) servi pour /.well-known/assetlinks.json (voir .htaccess)
if (isset($_GET['assetlinks'])) {
  header('Cache-Control: public, max-age=3600');
  echo '[{"relation":["delegate_permission/common.handle_all_urls"],"target":{"namespace":"android_app","package_name":"com.blessmax.danxome","sha256_cert_fingerprints":["5A:43:ED:8E:70:1E:FF:63:4C:A8:BB:A1:0F:1F:30:06:A6:1A:BC:B6:2C:05:47:C0:A3:FC:12:AF:DE:E8:D2:B1"]}}]';
  exit;
}
// Clé admin : empreinte stockée hors du dépôt public (fichier data/admin.hash sur le serveur)
$__ah=@file_get_contents(dirname(__DIR__).'/data/admin.hash');
define('ADMIN_HASH',($__ah&&preg_match('/^[a-f0-9]{64}$/',trim($__ah)))?trim($__ah):str_repeat('0',64));
const CITIES=['Abomey','Cotonou','Porto-Novo','Ouidah','Parakou','Abomey-Calavi','Bohicon','Natitingou','Djougou','Lokossa','Kandi','Savalou','Dassa-Zoumè','Comè','Allada','Pobè','Malanville','Savè','Kétou','Grand-Popo','Autre ville','Hors du Bénin'];
const EVENTS=['open','mission_start','mission_win','mission_loss','battle_win','battle_loss','inf_end','daily_end','profile','season_end'];
function out($a,$code=200){http_response_code($code);echo json_encode($a,JSON_UNESCAPED_UNICODE);exit;}
function fail($m,$code=400){out(['ok'=>false,'error'=>$m],$code);}
$dir=dirname(__DIR__).'/data';if(!is_dir($dir))@mkdir($dir,0700,true);
try{$db=new PDO('sqlite:'.$dir.'/danxome.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}catch(Exception $e){fail('base indisponible',500);}
$db->exec("PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS players(id TEXT PRIMARY KEY,token TEXT,code TEXT UNIQUE,pseudo TEXT,pkey TEXT UNIQUE,city TEXT,created INTEGER,save TEXT,save_at INTEGER);
CREATE TABLE IF NOT EXISTS scores(player TEXT,mode TEXT,period TEXT,value INTEGER,at INTEGER,PRIMARY KEY(player,mode,period));
CREATE INDEX IF NOT EXISTS sc_top ON scores(mode,period,value DESC);
CREATE TABLE IF NOT EXISTS stats(day TEXT,ev TEXT,k TEXT,n INTEGER,PRIMARY KEY(day,ev,k));
CREATE TABLE IF NOT EXISTS rate(h TEXT,m INTEGER,n INTEGER,PRIMARY KEY(h,m));");
// limitation de débit : 90 requêtes par minute et par appareil (IP hachée, jamais stockée en clair)
$m=intdiv(time(),60);$h=hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.date('Y-m-d').'|danxome');
$db->prepare("INSERT INTO rate(h,m,n) VALUES(?,?,1) ON CONFLICT(h,m) DO UPDATE SET n=n+1")->execute([$h,$m]);
$q=$db->prepare("SELECT n FROM rate WHERE h=? AND m=?");$q->execute([$h,$m]);if((int)$q->fetchColumn()>90)fail('trop de requêtes',429);
if(random_int(0,50)===0)$db->prepare("DELETE FROM rate WHERE m<?")->execute([$m-5]);
$in=json_decode(file_get_contents('php://input')?:'[]',true)?:[];$in=array_merge($_GET,$in);
$a=$in['a']??'';
function norm($s){$s=mb_strtolower($s,'UTF-8');$s=strtr($s,['0'=>'o','1'=>'i','3'=>'e','4'=>'a','5'=>'s','7'=>'t','8'=>'b','@'=>'a','$'=>'s']);return preg_replace('/[^a-z]/','',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s);}
function badword($p){$n=norm($p);foreach(['merde','putain','pute','connard','connasse','salope','encule','nique','batard','bite','couille','fuck','shit','bitch','nazi','hitler','pede','negre','nigga','nigger','admin','blessmax','danxome'] as $w){if(strpos($n,$w)!==false)return true;}return false;}
function auth($db,$in){$id=$in['id']??'';$tk=$in['token']??'';if(!preg_match('/^[a-f0-9]{16}$/',$id)||!preg_match('/^[a-f0-9]{32}$/',$tk))fail('profil invalide',401);
  $q=$db->prepare("SELECT * FROM players WHERE id=?");$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
  if(!$p||!hash_equals($p['token'],hash('sha256',$tk)))fail('profil invalide',401);return $p;}
function newcode(){$al='ABCDEFGHJKMNPQRSTUVWXYZ23456789';$c='';for($i=0;$i<8;$i++)$c.=$al[random_int(0,strlen($al)-1)];return 'DAN-'.substr($c,0,4).'-'.substr($c,4);}
function addstat($db,$ev,$k){$db->prepare("INSERT INTO stats(day,ev,k,n) VALUES(?,?,?,1) ON CONFLICT(day,ev,k) DO UPDATE SET n=n+1")->execute([date('Y-m-d'),$ev,$k]);}
switch($a){
case 'register':
  $p=trim(preg_replace('/\s+/u',' ',(string)($in['pseudo']??'')));$c=(string)($in['city']??'');
  if(!preg_match('/^[\p{L}\p{N} _\-]{3,16}$/u',$p))fail('Le pseudo doit faire 3 à 16 lettres ou chiffres.');
  if(badword($p))fail('Ce pseudo n\'est pas autorisé. Choisis-en un autre.');
  if(!in_array($c,CITIES,true))fail('Ville inconnue.');
  $pk=mb_strtolower($p,'UTF-8');$q=$db->prepare("SELECT 1 FROM players WHERE pkey=?");$q->execute([$pk]);if($q->fetch())fail('Ce pseudo est déjà pris. Choisis-en un autre.');
  $id=bin2hex(random_bytes(8));$tk=bin2hex(random_bytes(16));
  for($i=0;$i<5;$i++){$code=newcode();$q=$db->prepare("SELECT 1 FROM players WHERE code=?");$q->execute([$code]);if(!$q->fetch())break;}
  $db->prepare("INSERT INTO players(id,token,code,pseudo,pkey,city,created) VALUES(?,?,?,?,?,?,?)")->execute([$id,hash('sha256',$tk),$code,$p,$pk,$c,time()]);
  addstat($db,'profile',$c);
  out(['ok'=>true,'id'=>$id,'token'=>$tk,'code'=>$code,'pseudo'=>$p,'city'=>$c]);
case 'city':
  $p=auth($db,$in);$c=(string)($in['city']??'');if(!in_array($c,CITIES,true))fail('Ville inconnue.');
  $db->prepare("UPDATE players SET city=? WHERE id=?")->execute([$c,$p['id']]);out(['ok'=>true]);
case 'score':
  $p=auth($db,$in);$mode=(string)($in['mode']??'');$v=(int)($in['value']??0);$per=(string)($in['period']??'all');
  if($mode==='inf'){if($v<0||$v>400)fail('score invalide');$per='all';}
  elseif($mode==='jour'){if($v<0||$v>3000||!preg_match('/^\d{1,8}$/',$per))fail('score invalide');}
  else fail('mode inconnu');
  $db->prepare("INSERT INTO scores(player,mode,period,value,at) VALUES(?,?,?,?,?) ON CONFLICT(player,mode,period) DO UPDATE SET value=excluded.value,at=excluded.at WHERE excluded.value>scores.value")->execute([$p['id'],$mode,$per,$v,time()]);
  out(['ok'=>true]);
case 'top':
  $mode=(string)($in['mode']??'inf');$per=$mode==='inf'?'all':(string)($in['period']??'');$city=(string)($in['city']??'');$lim=max(1,min(50,(int)($in['limit']??20)));
  if(!in_array($mode,['inf','jour'],true))fail('mode inconnu');
  $sql="SELECT p.pseudo,p.city,s.value FROM scores s JOIN players p ON p.id=s.player WHERE s.mode=? AND s.period=?";$args=[$mode,$per];
  if($city!==''){if(!in_array($city,CITIES,true))fail('Ville inconnue.');$sql.=" AND p.city=?";$args[]=$city;}
  $sql.=" ORDER BY s.value DESC,s.at ASC LIMIT $lim";$q=$db->prepare($sql);$q->execute($args);
  $me=null;
  if(!empty($in['id'])&&preg_match('/^[a-f0-9]{16}$/',$in['id'])){
    $q2=$db->prepare("SELECT value FROM scores WHERE player=? AND mode=? AND period=?");$q2->execute([$in['id'],$mode,$per]);$v=$q2->fetchColumn();
    if($v!==false){$q3=$db->prepare("SELECT COUNT(*) FROM scores WHERE mode=? AND period=? AND value>?");$q3->execute([$mode,$per,$v]);$me=['value'=>(int)$v,'rank'=>(int)$q3->fetchColumn()+1];}
  }
  $t=$db->prepare("SELECT COUNT(*) FROM scores WHERE mode=? AND period=?");$t->execute([$mode,$per]);
  out(['ok'=>true,'top'=>$q->fetchAll(PDO::FETCH_ASSOC),'me'=>$me,'total'=>(int)$t->fetchColumn()]);
case 'save':
  $p=auth($db,$in);$d=(string)($in['data']??'');if(strlen($d)>300000)fail('sauvegarde trop grande');json_decode($d);if(json_last_error())fail('sauvegarde invalide');
  $db->prepare("UPDATE players SET save=?,save_at=? WHERE id=?")->execute([$d,time(),$p['id']]);out(['ok'=>true,'at'=>time()]);
case 'load':
  $code=strtoupper(trim((string)($in['code']??'')));if(!preg_match('/^DAN-[A-Z0-9]{4}-[A-Z0-9]{4}$/',$code))fail('Code invalide. Il ressemble à DAN-XXXX-XXXX.');
  $q=$db->prepare("SELECT * FROM players WHERE code=?");$q->execute([$code]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)fail('Aucun profil avec ce code.',404);
  $tk=bin2hex(random_bytes(16));$db->prepare("UPDATE players SET token=? WHERE id=?")->execute([hash('sha256',$tk),$p['id']]);
  out(['ok'=>true,'id'=>$p['id'],'token'=>$tk,'code'=>$p['code'],'pseudo'=>$p['pseudo'],'city'=>$p['city'],'data'=>$p['save'],'at'=>(int)$p['save_at']]);
case 'stat':
  $ev=(string)($in['ev']??'');$k=substr(preg_replace('/[^A-Za-z0-9_\-]/','',(string)($in['k']??'')),0,24);
  if(!in_array($ev,EVENTS,true))fail('évènement inconnu');addstat($db,$ev,$k);out(['ok'=>true]);
case 'stats':
  if(!hash_equals(ADMIN_HASH,hash('sha256',(string)($in['key']??''))))fail('clé invalide',403);
  $r=['ok'=>true];
  $r['joueurs']=(int)$db->query("SELECT COUNT(*) FROM players")->fetchColumn();
  $r['joueurs_par_ville']=$db->query("SELECT city,COUNT(*) n FROM players GROUP BY city ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
  $r['par_jour']=$db->query("SELECT day,ev,SUM(n) n FROM stats WHERE day>=date('now','-30 day') GROUP BY day,ev ORDER BY day DESC,ev")->fetchAll(PDO::FETCH_ASSOC);
  $r['missions']=$db->query("SELECT ev,k,SUM(n) n FROM stats WHERE ev IN ('mission_win','mission_loss','battle_win','battle_loss') GROUP BY ev,k ORDER BY k,ev")->fetchAll(PDO::FETCH_ASSOC);
  $r['ouvertures_total']=(int)$db->query("SELECT COALESCE(SUM(n),0) FROM stats WHERE ev='open'")->fetchColumn();
  out($r);
case 'admin':
  if(!hash_equals(ADMIN_HASH,hash('sha256',(string)($in['key']??''))))fail('clé invalide',403);
  $T="'unixepoch','+1 hour'"; // heure du Bénin
  $r=['ok'=>true,'now'=>time()];
  $r['joueurs']=(int)$db->query("SELECT COUNT(*) FROM players")->fetchColumn();
  $r['avec_sauvegarde']=(int)$db->query("SELECT COUNT(*) FROM players WHERE save_at IS NOT NULL")->fetchColumn();
  $r['actifs_7j']=(int)$db->query("SELECT COUNT(*) FROM players WHERE save_at>=".(time()-7*86400))->fetchColumn();
  $r['aujourdhui']=(int)$db->query("SELECT COUNT(*) FROM players WHERE date(created,$T)=date('now','+1 hour')")->fetchColumn();
  $r['inscrits_par_jour']=$db->query("SELECT date(created,$T) day,COUNT(*) n FROM players GROUP BY day ORDER BY day")->fetchAll(PDO::FETCH_ASSOC);
  $r['villes']=$db->query("SELECT city,COUNT(*) n FROM players GROUP BY city ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
  $r['activite_par_jour']=$db->query("SELECT day,ev,SUM(n) n FROM stats WHERE day>=date('now','-60 day') GROUP BY day,ev ORDER BY day")->fetchAll(PDO::FETCH_ASSOC);
  $r['totaux']=$db->query("SELECT ev,SUM(n) n FROM stats GROUP BY ev")->fetchAll(PDO::FETCH_KEY_PAIR);
  $r['missions']=$db->query("SELECT k,ev,SUM(n) n FROM stats WHERE ev IN ('mission_start','mission_win','mission_loss','battle_win','battle_loss') GROUP BY k,ev ORDER BY k")->fetchAll(PDO::FETCH_ASSOC);
  $r['classement_inf']=$db->query("SELECT p.pseudo,p.city,s.value,s.at FROM scores s JOIN players p ON p.id=s.player WHERE s.mode='inf' ORDER BY s.value DESC,s.at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
  $r['classement_jour']=$db->query("SELECT p.pseudo,p.city,s.value,s.period FROM scores s JOIN players p ON p.id=s.player WHERE s.mode='jour' AND s.period=(SELECT MAX(period) FROM scores WHERE mode='jour') ORDER BY s.value DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
  // liste des joueurs : jamais le jeton ni le code de sauvegarde
  $r['liste']=$db->query("SELECT p.pseudo,p.city,p.created,p.save_at,(SELECT value FROM scores WHERE player=p.id AND mode='inf') inf,(SELECT COUNT(*) FROM scores WHERE player=p.id AND mode='jour') defis FROM players p ORDER BY p.created DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
  out($r);
default: fail('action inconnue',404);
}
