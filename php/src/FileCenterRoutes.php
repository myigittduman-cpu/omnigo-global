<?php
declare(strict_types=1);
namespace Omnigo;

final class FileCenterRoutes
{
    public function __construct(private Database $db, private Security $security) {}

    public function handle(string $path, string $method, array $user): void
    {
        if (!preg_match('#^/api/file-center/(products|archive)(?:/([1-9][0-9]*|selection))?$#D', $path, $match)) return;
        Security::admin($user);
        $company = $this->security->company($user, $_GET['company'] ?? null);
        if($match[1]==='products'&&!CustomerSetupSelection::hasLive($this->db,$company,'fileCenter')){
            Http::fail(403,'Pazaryeri Ürünleri bu mağazanın çalışma kapsamında etkin değil.');
        }
        $page = max(0, (int)($_GET['page'] ?? 0));
        $target = $match[2] ?? null;
        $actor = (int)$user['id'];
        if ($match[1] === 'products' && !isset($match[2]) && $method === 'GET') {
            $query = mb_substr(trim(is_string($_GET['query'] ?? null) ? $_GET['query'] : ''), 0, 120);
            $sort = Validation::choice($_GET['sort'] ?? 'stable',['stable','sku','name_asc','name_desc']);
            $brand = InventoryProductBrands::selection($_GET['brand'] ?? '');
            Http::json((new FileCenterProducts($this->db))->listing($company, $page, $query, $sort, $brand));
        }
        if ($match[1] === 'products' && $target === 'selection' && $method === 'DELETE') {
            $count = FileCenterSelection::products($this->db,$company,$actor,Http::body()['items'] ?? null);
            Http::json(['removed'=>$count]);
        }
        if ($match[1] === 'archive') {
            $storage = dirname(__DIR__).'/storage';
            $archive = new FileCenterArchive($this->db, new DeliveryCipher($storage.'/backup.key'), $storage.'/file-center');
            if ($method === 'DELETE' && $target === 'selection') {
                Http::json(['removed'=>$archive->remove($company,$actor,Http::body()['items'] ?? null)]);
            }
            if ($method === 'GET' && $target === null) Http::json($archive->listing($company, $actor, $page));
            if ($method === 'GET' && $target !== null && ctype_digit($target)) {
                $file = $archive->read($company, $actor, (int)$target);
                header('Content-Type: '.$file['mime']);
                header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['name']));
                header('Content-Length: '.strlen($file['bytes']));
                echo $file['bytes'];
                exit;
            }
            if ($method === 'POST' && $target === null) {
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                    Http::fail(400, 'Rapor dosyası arşive aktarılamadı.');
                }
                if ($file['size'] > FileCenterArchive::MAX_BYTES) Http::fail(413, 'Dosya arşiv sınırını aşıyor.');
                $category = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
                Http::json($archive->save($company, $actor, $category, (string)$file['name'], file_get_contents($file['tmp_name'])), 201);
            }
        }
        Http::fail(405, 'Bu dosya işlemi desteklenmiyor.');
    }
}
