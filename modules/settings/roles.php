<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - ROL, YETKİLENDİRME (RBAC) & KULLANICI YÖNETİMİ
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_permission('settings.manage');

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A. Yeni Rol Ekleme
    if ($action === 'create_role') {
        $role_name   = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $tr_map      = ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'İ' => 'i', 'Ğ' => 'g', 'Ü' => 'u', 'Ş' => 's', 'Ö' => 'o', 'Ç' => 'c', ' ' => '_'];
        $role_slug   = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', strtr($role_name, $tr_map)));
        if ($role_slug === '') {
            $role_slug = 'rol_' . time();
        }
        $slug_chk = $db->prepare("SELECT COUNT(*) FROM roles WHERE role_slug = ?");
        $slug_chk->execute([$role_slug]);
        if ((int)$slug_chk->fetchColumn() > 0) {
            $role_slug .= '_' . substr((string)time(), -4);
        }

        if (!empty($role_name)) {
            $stmt = $db->prepare("INSERT INTO roles (role_name, role_slug, description) VALUES (?, ?, ?)");
            $stmt->execute([$role_name, $role_slug, $description]);
            set_flash('success', "{$role_name} rolü oluşturuldu.");
            redirect(BASE_URL . '/modules/settings/roles.php');
        }
    }

    // B. Rol İzinlerini Güncelleme
    if ($action === 'update_permissions') {
        $role_id = (int)$_POST['role_id'];
        $selected_perms = $_POST['permissions'] ?? [];

        if ($role_id > 1) {
            $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
            if (!empty($selected_perms)) {
                $ins_stmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($selected_perms as $perm_id) {
                    $ins_stmt->execute([$role_id, (int)$perm_id]);
                }
            }
            set_flash('success', 'Rol izin matrisi güncellendi.');
            redirect(BASE_URL . "/modules/settings/roles.php?selected_role={$role_id}");
        }
    }

    // C. Yeni Sistem Kullanıcısı Oluşturma
    if ($action === 'create_user') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role_id   = (int)($_POST['role_id'] ?? 2);
        $phone     = trim($_POST['phone'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Lütfen geçerli bir e-posta adresi giriniz.');
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }
        if ($perr = password_policy_error($password)) {
            set_flash('error', $perr);
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }
        $dup = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ((int)$dup->fetchColumn() > 0) {
            set_flash('error', 'Bu e-posta adresi zaten kullanımda.');
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }

        if (!empty($full_name) && !empty($email) && !empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("INSERT INTO users (role_id, full_name, email, password, phone, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->execute([$role_id, $full_name, $email, $hashed, $phone]);
                set_flash('success', "{$full_name} kullanıcısı oluşturuldu.");
                redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
            } catch (PDOException $e) {
                set_flash('error', 'Kullanıcı oluşturulamadı.');
                redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
            }
        }
    }

    // D. Kullanıcı Düzenleme & Şifre Değiştirme
    if ($action === 'update_user') {
        $user_id   = (int)$_POST['user_id'];
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $role_id   = (int)$_POST['role_id'];
        $status    = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        $password  = $_POST['password'] ?? '';

        // Kendi hesabını pasife alma / rolünü düşürme ve ana yöneticinin rolünü değiştirme engellenir
        if ($user_id === (int)$_SESSION['user_id']) {
            $status  = 'active';
            $role_id = (int)$_SESSION['user']['role_id'];
        }
        if ($user_id === 1) {
            $role_id = 1;
            $status  = 'active';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Lütfen geçerli bir e-posta adresi giriniz.');
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }
        if ($password !== '' && ($perr = password_policy_error($password))) {
            set_flash('error', $perr);
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }

        if ($user_id > 0 && !empty($full_name) && !empty($email)) {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $user_id]);
            if ($chk->fetch()) {
                set_flash('error', 'Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.');
            } else {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, status = ?, password = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email, $phone, $role_id, $status, $hash, $user_id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, status = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email, $phone, $role_id, $status, $user_id]);
                }

                if ($user_id === (int)$_SESSION['user_id']) {
                    $_SESSION['user']['full_name'] = $full_name;
                    $_SESSION['user']['email']     = $email;
                    $_SESSION['user']['phone']     = $phone;
                    $_SESSION['user']['role_id']   = $role_id;
                }

                set_flash('success', "{$full_name} kullanıcısının bilgileri güncellendi.");
            }
            redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
        }
    }

    // E. Kullanıcı Silme
    if ($action === 'delete_user') {
        $del_id = (int)$_POST['user_id'];
        if ($del_id === (int)$_SESSION['user_id']) {
            set_flash('error', 'Kendi oturum açtığınız hesabı silemezsiniz!');
        } elseif ($del_id === 1) {
            set_flash('error', 'Ana Süper Yönetici hesabı silinemez!');
        } else {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$del_id]);
            set_flash('success', 'Kullanıcı hesabı silindi.');
        }
        redirect(BASE_URL . '/modules/settings/roles.php?tab=users');
    }
}

// 2. VERİLERİ ÇEKME
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$permissions = $db->query("SELECT * FROM permissions ORDER BY module ASC, id ASC")->fetchAll();

$grouped_permissions = [];
foreach ($permissions as $p) {
    $grouped_permissions[$p['module']][] = $p;
}

$selected_role_id = (int)($_GET['selected_role'] ?? 2);
$current_role_perms = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
$current_role_perms->execute([$selected_role_id]);
$active_perm_ids = $current_role_perms->fetchAll(PDO::FETCH_COLUMN);

$users_list = $db->query("
    SELECT u.*, r.role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
")->fetchAll();

$active_tab = $_GET['tab'] ?? 'roles';

$page_title = 'Roller & Kullanıcı Yönetimi';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ 
    tab: '<?= e($active_tab) ?>', 
    openRoleModal: false, 
    openUserModal: false,
    openEditUserModal: false,
    editUser: { id: '', full_name: '', email: '', phone: '', role_id: '', status: 'active' }
}">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Yetkilendirme & Kullanıcı Yönetimi</h1>
            <p class="text-xs text-slate-500 mt-0.5">Dinamik RBAC rol izinleri ve ajans kullanıcı hesapları.</p>
        </div>

        <div class="flex items-center gap-2">
            <button x-show="tab === 'roles'" @click="openRoleModal = true" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
                <i data-lucide="shield-plus" class="w-4 h-4"></i>
                <span>Yeni Rol Oluştur</span>
            </button>
            <button x-show="tab === 'users'" @click="openUserModal = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                <span>Yeni Kullanıcı Ekle</span>
            </button>
        </div>
    </div>

    <!-- Sekmeler -->
    <div class="flex border-b border-slate-200 mb-6 gap-6">
        <button @click="tab = 'roles'" :class="tab === 'roles' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
            <i data-lucide="shield-check" class="w-4 h-4"></i>
            <span>Roller & Yetki Matrisi</span>
        </button>
        <button @click="tab = 'users'" :class="tab === 'users' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
            <i data-lucide="users" class="w-4 h-4"></i>
            <span>Sistem Kullanıcıları (<?= count($users_list) ?>)</span>
        </button>
    </div>

    <!-- SEKME 1: ROLLER -->
    <div x-show="tab === 'roles'" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-4 bg-white p-4 rounded-3xl border border-slate-200 shadow-sm space-y-2">
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider px-2 mb-3">Ajans Rolleri</h3>
            <?php foreach ($roles as $r): 
                $is_selected = ($r['id'] === $selected_role_id);
            ?>
            <a href="<?= BASE_URL ?>/modules/settings/roles.php?selected_role=<?= $r['id'] ?>"
               class="flex items-center justify-between p-3.5 rounded-2xl border transition <?= $is_selected ? 'bg-brand-50 border-brand-300 text-brand-900 font-bold' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100' ?>">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl <?= $is_selected ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-600' ?> flex items-center justify-center text-xs">
                        <i data-lucide="shield" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <p class="text-xs"><?= e($r['role_name']) ?></p>
                        <span class="text-[10px] text-slate-400 font-normal"><?= e($r['role_slug']) ?></span>
                    </div>
                </div>
                <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="lg:col-span-8 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <?php 
                $active_role_data = array_filter($roles, fn($r) => $r['id'] === $selected_role_id);
                $active_role = reset($active_role_data);
            ?>
            <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900"><?= e($active_role['role_name']) ?> — Erişim İzinleri</h3>
                    <p class="text-xs text-slate-500 mt-0.5"><?= e($active_role['description'] ?? 'Rol izinleri.') ?></p>
                </div>
            </div>

            <form method="POST" action="" class="space-y-6">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="role_id" value="<?= $selected_role_id ?>">

                <?php foreach ($grouped_permissions as $module_name => $perms): ?>
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200">
                    <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-brand-600"></span>
                        <span><?= strtoupper($module_name) ?></span>
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php foreach ($perms as $perm): 
                            $checked = in_array($perm['id'], $active_perm_ids) || $selected_role_id === 1;
                            $disabled = ($selected_role_id === 1);
                        ?>
                        <label class="flex items-start space-x-3 p-2.5 bg-white rounded-xl border border-slate-200 text-xs cursor-pointer hover:bg-brand-50 transition">
                            <input type="checkbox" name="permissions[]" value="<?= $perm['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?> class="rounded text-brand-600 mt-0.5">
                            <div>
                                <span class="font-bold text-slate-900 block"><?= e($perm['description']) ?></span>
                                <span class="text-[10px] font-mono text-slate-400"><?= e($perm['permission_key']) ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($selected_role_id !== 1): ?>
                <div class="pt-4 border-t border-slate-100 flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold rounded-xl shadow-md transition">
                        İzinleri Kaydet
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- SEKME 2: KULLANICILAR -->
    <div x-show="tab === 'users'" x-cloak class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3.5 px-4">Kullanıcı</th>
                        <th class="py-3.5 px-4">E-Posta & Telefon</th>
                        <th class="py-3.5 px-4">Rol</th>
                        <th class="py-3.5 px-4">Son Giriş</th>
                        <th class="py-3.5 px-4 text-center">Durum</th>
                        <th class="py-3.5 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($users_list as $u): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="py-3.5 px-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-slate-900 text-white font-bold flex items-center justify-center text-xs">
                                    <?= strtoupper(mb_substr($u['full_name'], 0, 1, 'UTF-8')) ?>
                                </div>
                                <div>
                                    <span class="font-bold text-slate-900 block"><?= e($u['full_name']) ?></span>
                                    <?php if ($u['id'] === (int)$_SESSION['user_id']): ?>
                                        <span class="text-[10px] text-brand-600 font-bold">(Siz)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="py-3.5 px-4">
                            <div class="font-medium text-slate-800"><?= e($u['email']) ?></div>
                            <span class="text-[11px] text-slate-400"><?= e($u['phone'] ?? '-') ?></span>
                        </td>
                        <td class="py-3.5 px-4">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-brand-50 text-brand-700 border border-brand-200">
                                <?= e($u['role_name']) ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 text-slate-500"><?= !empty($u['last_login']) ? format_date($u['last_login'], true) : 'Giriş Yapmadı' ?></td>
                        <td class="py-3.5 px-4 text-center">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $u['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                <?= $u['status'] === 'active' ? 'AKTİF' : 'PASİF' ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button @click="editUser = { 
                                            id: '<?= $u['id'] ?>', 
                                            full_name: <?= js_val($u['full_name']) ?>, 
                                            email: <?= js_val($u['email']) ?>, 
                                            phone: <?= js_val($u['phone'] ?? '') ?>, 
                                            role_id: '<?= $u['role_id'] ?>', 
                                            status: '<?= $u['status'] ?>' 
                                        }; openEditUserModal = true"
                                        class="inline-flex items-center gap-1 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-700 text-xs font-bold py-1.5 px-3 rounded-lg border border-slate-200 transition">
                                    <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                    <span>Düzenle</span>
                                </button>

                                <?php if ($u['id'] !== (int)$_SESSION['user_id'] && $u['id'] !== 1): ?>
                                <form method="POST" action="" onsubmit="return confirm('Kullanıcıyı silmek istiyor musunuz?');" class="inline-block">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition" title="Sil">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL 1: KULLANICI DÜZENLE -->
    <div x-show="openEditUserModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openEditUserModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Kullanıcı Bilgilerini Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" :value="editUser.id">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ad Soyad *</label>
                    <input type="text" name="full_name" required x-model="editUser.full_name" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">E-Posta *</label>
                        <input type="email" name="email" required x-model="editUser.email" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Telefon</label>
                        <input type="text" name="phone" x-model="editUser.phone" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Rol</label>
                        <select name="role_id" x-model="editUser.role_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach ($roles as $rl): ?>
                                <option value="<?= $rl['id'] ?>"><?= e($rl['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Durum</label>
                        <select name="status" x-model="editUser.status" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="active">Aktif</option>
                            <option value="inactive">Pasif</option>
                        </select>
                    </div>
                </div>

                <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200">
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Şifre Sıfırla (Opsiyonel)</label>
                    <input type="password" name="password" placeholder="Değiştirmek istemiyorsanız boş bırakın" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditUserModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: YENİ KULLANICI -->
    <div x-show="openUserModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openUserModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Yeni Kullanıcı Aç</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ad Soyad *</label>
                    <input type="text" name="full_name" required placeholder="Ad Soyad" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">E-Posta *</label>
                    <input type="email" name="email" required placeholder="isim@rymedya.com.tr" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Şifre *</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Rol *</label>
                    <select name="role_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <?php foreach ($roles as $rl): ?>
                            <option value="<?= $rl['id'] ?>"><?= e($rl['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openUserModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>