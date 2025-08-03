<?php
// api/migration.php
// Script untuk membuat database dan migrate data existing ke database

require_once 'config.php';

class DatabaseMigration {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function createTables() {
        try {
            // Create pengguna table
            $sql_users = "
                CREATE TABLE IF NOT EXISTS pengguna (
                    id_user INT(10) PRIMARY KEY AUTO_INCREMENT,
                    nama VARCHAR(50) NOT NULL,
                    email VARCHAR(40) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $this->pdo->exec($sql_users);
            echo "✅ Tabel 'pengguna' berhasil dibuat\n";
            
            // Create fasilitas_umum table
            $sql_facilities = "
                CREATE TABLE IF NOT EXISTS fasilitas_umum (
                    id_fasilitas INT(11) PRIMARY KEY AUTO_INCREMENT,
                    nama_fasilitas VARCHAR(100) NOT NULL,
                    foto_fasilitas VARCHAR(250),
                    alamat TEXT,
                    deskripsi TEXT,
                    latitude VARCHAR(100) NOT NULL,
                    longitude VARCHAR(100) NOT NULL,
                    kategori VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ";
            $this->pdo->exec($sql_facilities);
            echo "✅ Tabel 'fasilitas_umum' berhasil dibuat\n";
            
            return true;
        } catch (PDOException $e) {
            echo "❌ Error creating tables: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function createDefaultAdmin() {
        try {
            // Check if admin already exists
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pengguna WHERE email = ?");
            $stmt->execute(['admin@cirendeu.com']);
            
            if ($stmt->fetchColumn() == 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO pengguna (nama, email, password) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([
                    'Administrator',
                    'admin@cirendeu.com',
                    password_hash('admin123', PASSWORD_DEFAULT)
                ]);
                echo "✅ Default admin user berhasil dibuat\n";
                echo "   Email: admin@cirendeu.com\n";
                echo "   Password: admin123\n";
            } else {
                echo "ℹ️  Admin user sudah ada\n";
            }
            
            return true;
        } catch (PDOException $e) {
            echo "❌ Error creating admin: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function migrateFacilitiesData() {
        try {
            // Check if data already exists
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM fasilitas_umum");
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                echo "ℹ️  Data fasilitas sudah ada, skip migration\n";
                return true;
            }
            
            $facilities = $this->getExistingFacilitiesData();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO fasilitas_umum (nama_fasilitas, alamat, deskripsi, latitude, longitude, kategori) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $total = 0;
            foreach ($facilities as $category => $data) {
                foreach ($data['features'] as $feature) {
                    $coordinates = $feature['geometry']['coordinates'];
                    $properties = $feature['properties'];
                    
                    // Extract name from different property keys
                    $name = $properties['Nama'] ?? $properties['nama'] ?? $properties['jalan'] ?? 'Unknown';
                    $address = $this->generateAddress($coordinates);
                    $description = $this->getCategoryDescription($category);
                    
                    $stmt->execute([
                        $name,
                        $address,
                        $description,
                        (string)$coordinates[1], // latitude
                        (string)$coordinates[0], // longitude
                        $this->getCategoryName($category)
                    ]);
                    $total++;
                }
            }
            
            echo "✅ $total data fasilitas berhasil dimigrasikan\n";
            return true;
            
        } catch (PDOException $e) {
            echo "❌ Error migrating data: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function getExistingFacilitiesData() {
        return [
            'masjid' => [
                'features' => [
                    ['geometry' => ['coordinates' => [106.766284704295728, -6.292477655176009]], 'properties' => ['Nama' => 'Masjid Darussa\'adah']],
                    ['geometry' => ['coordinates' => [106.767657995338226, -6.293074849943859]], 'properties' => ['Nama' => 'Masjid LDII Baitul Majid']],
                    ['geometry' => ['coordinates' => [106.769406795842769, -6.296231439509424]], 'properties' => ['Nama' => 'Mushalla Al Ikhlas Perumahan Bukit Pratama']],
                    ['geometry' => ['coordinates' => [106.765115261334927, -6.298108321474375]], 'properties' => ['Nama' => 'Masjid nurul huda']],
                    ['geometry' => ['coordinates' => [106.770050526018949, -6.297340506945131]], 'properties' => ['Nama' => 'Masjid Al Mukhlishin UMJ']],
                    ['geometry' => ['coordinates' => [106.768033504670868, -6.300582382844412]], 'properties' => ['Nama' => 'Masjid Raya Jabalurrahmah Situ Gintung']],
                    ['geometry' => ['coordinates' => [106.761386, -6.304252]], 'properties' => ['Nama' => 'Masjid Al-Irfan']],
                    ['geometry' => ['coordinates' => [106.766145229487392, -6.304037517696772]], 'properties' => ['Nama' => 'Masjid At - Taqwa UMJ']],
                    ['geometry' => ['coordinates' => [106.772196293143452, -6.30284315269596]], 'properties' => ['Nama' => 'Masjid Nurul Muhajirin']],
                    ['geometry' => ['coordinates' => [106.767218113114353, -6.306895451354221]], 'properties' => ['Nama' => 'Masjid Al Istiqomah']],
                    ['geometry' => ['coordinates' => [106.772496700559003, -6.305914371426527]], 'properties' => ['Nama' => 'Masjid Al-Fattaah Cirendeu']],
                    ['geometry' => ['coordinates' => [106.761349439762981, -6.310649131481546]], 'properties' => ['Nama' => 'Masjid Al Ikhlass']],
                    ['geometry' => ['coordinates' => [106.771048307750675, -6.30877229489484]], 'properties' => ['Nama' => 'Masjid Al-Barkah']],
                    ['geometry' => ['coordinates' => [106.769160032423542, -6.314744024157859]], 'properties' => ['Nama' => 'Masjid Al-Mughirah']],
                    ['geometry' => ['coordinates' => [106.771734953128259, -6.314616059254634]], 'properties' => ['Nama' => 'Masjid Attaubah']],
                    ['geometry' => ['coordinates' => [106.772207021924117, -6.318838884358839]], 'properties' => ['Nama' => 'Mesjid Al Ikhlas']],
                    ['geometry' => ['coordinates' => [106.767743826035982, -6.321824699493323]], 'properties' => ['Nama' => 'Masjid Al-Ihsan']],
                    ['geometry' => ['coordinates' => [106.761435270309462, -6.324767843255523]], 'properties' => ['Nama' => 'Masjid Jami\' Al Hidayah, Pisangan Ciputat Timur']]
                ]
            ],
            'pendidikan' => [
                'features' => [
                    ['geometry' => ['coordinates' => [106.767054163032086, -6.297450480473733]], 'properties' => ['Nama' => 'Universitas Muhammadiyah jakarta']],
                    ['geometry' => ['coordinates' => [106.766456573779138, -6.295993623809376]], 'properties' => ['Nama' => 'ITB Ahmad Dahlan Jakarta']],
                    ['geometry' => ['coordinates' => [106.760083530942751, -6.309130626850863]], 'properties' => ['Nama' => 'UIN Syarif Hidayatullah']],
                    ['geometry' => ['coordinates' => [106.768966840587765, -6.298342676248416]], 'properties' => ['Nama' => 'Labschool UMJ']],
                    ['geometry' => ['coordinates' => [106.760134355899311, -6.31521819016021]], 'properties' => ['Nama' => 'Labschool Ruhama']],
                    ['geometry' => ['coordinates' => [106.770019281061366, -6.31411148116436]], 'properties' => ['Nama' => 'SMAN 8 Tangerang Selatan']],
                    ['geometry' => ['coordinates' => [106.76990228106142, -6.313809272238827]], 'properties' => ['Nama' => 'SMPN 2 Tangerang selatan']],
                    ['geometry' => ['coordinates' => [106.771575749840352, -6.313670232225169]], 'properties' => ['Nama' => 'SD Negri 3 Cirendeu']],
                    ['geometry' => ['coordinates' => [106.781219914933772, -6.294650541461807]], 'properties' => ['Nama' => 'SD Negri 1 cirendeu']],
                    ['geometry' => ['coordinates' => [106.760930684769079, -6.313572982185238]], 'properties' => ['Nama' => 'Darus-Sunnah International Institute for Hadith Sciences']],
                    ['geometry' => ['coordinates' => [106.760066308043804, -6.315230927928439]], 'properties' => ['Nama' => 'Yayasan Prof DR Zakiah Daradjat']],
                    ['geometry' => ['coordinates' => [106.765494486623041, -6.303456034116746]], 'properties' => ['Nama' => 'Madrasah Ibtidaiyah Al-Hidayah']],
                    ['geometry' => ['coordinates' => [106.763853209897491, -6.300632464932818]], 'properties' => ['Nama' => 'SD Tahfizh Jabal Rahmah']],
                    ['geometry' => ['coordinates' => [106.768439854085415, -6.318511011195734]], 'properties' => ['Nama' => 'SMP-SMA Al-Fath Cirendeu']]
                ]
            ],
            'kesehatan' => [
                'features' => [
                    ['geometry' => ['coordinates' => [106.7568111426163, -6.305786404402509]], 'properties' => ['nama' => 'RS Syarif Hidyatullah']],
                    ['geometry' => ['coordinates' => [106.759549346578382, -6.306545869013025]], 'properties' => ['nama' => 'RS Hermina']],
                    ['geometry' => ['coordinates' => [106.769229075990552, -6.315476711037605]], 'properties' => ['nama' => 'Klinik Gigi']],
                    ['geometry' => ['coordinates' => [106.766080893745269, -6.306010306224352]], 'properties' => ['nama' => 'Apotek Nurul']],
                    ['geometry' => ['coordinates' => [106.76574626987825, -6.296167684206583]], 'properties' => ['nama' => 'Apotek kawi jaya']],
                    ['geometry' => ['coordinates' => [106.765746269895502, -6.297042731189558]], 'properties' => ['nama' => 'Apotek manjur sehat']],
                    ['geometry' => ['coordinates' => [106.762477349156072, -6.311204262255567]], 'properties' => ['nama' => 'Apotek hana']],
                    ['geometry' => ['coordinates' => [106.759507047077378, -6.306393619485046]], 'properties' => ['nama' => 'Apotik']]
                ]
            ],
            'prasarana' => [
                'features' => [
                    ['geometry' => ['coordinates' => [106.766767496621938, -6.293885356737217]], 'properties' => ['nama' => 'MAKAM PONCOL']],
                    ['geometry' => ['coordinates' => [106.772947306313228, -6.311459610550556]], 'properties' => ['nama' => 'MAKAM TPU CIRENDEU INDAH III']],
                    ['geometry' => ['coordinates' => [106.765921825003815, -6.306692227611867]], 'properties' => ['nama' => 'TAMAN MAKAM KELUARGA TPK']],
                    ['geometry' => ['coordinates' => [106.764784996406405, -6.302091471689428]], 'properties' => ['nama' => 'JOGING TREK GINTUNG']],
                    ['geometry' => ['coordinates' => [106.762558828836248, -6.310079823310963]], 'properties' => ['nama' => 'TAMAN WISATA  PULAU GINTUNG']],
                    ['geometry' => ['coordinates' => [106.765185982915227, -6.299247723642017]], 'properties' => ['nama' => 'BALAI WARGA']],
                    ['geometry' => ['coordinates' => [106.770803921020956, -6.314463762685388]], 'properties' => ['nama' => 'KANTOR KELURAHAN']]
                ]
            ],
            'fasilitas' => [
                'features' => [
                    ['geometry' => ['coordinates' => [106.760895117419778, -6.302255227403706]], 'properties' => ['nama' => 'SPBU 1']],
                    ['geometry' => ['coordinates' => [106.765504373178956, -6.295846717458841]], 'properties' => ['nama' => 'HALTE 1']],
                    ['geometry' => ['coordinates' => [106.76097690016131, -6.302042744214146]], 'properties' => ['nama' => 'SPBU 2']],
                    ['geometry' => ['coordinates' => [106.761209964910137, -6.300896971629579]], 'properties' => ['nama' => 'HALTE 2']],
                    ['geometry' => ['coordinates' => [106.762569554079121, -6.310005170128058]], 'properties' => ['nama' => 'TAMAN WISATA PULAU GINTUNG']],
                    ['geometry' => ['coordinates' => [106.76088312286474, -6.307191234461524]], 'properties' => ['nama' => 'OBSTACLE REPUBLIK']]
                ]
            ]
        ];
    }
    
    private function generateAddress($coordinates) {
        // Generate basic address from coordinates
        return "Kelurahan Cirendeu, Kecamatan Ciputat Timur, Kota Tangerang Selatan, Banten";
    }
    
    private function getCategoryDescription($category) {
        $descriptions = [
            'masjid' => 'Tempat ibadah umat Islam',
            'pendidikan' => 'Lembaga pendidikan dan pembelajaran',
            'kesehatan' => 'Fasilitas pelayanan kesehatan',
            'prasarana' => 'Infrastruktur dan prasarana umum',
            'fasilitas' => 'Fasilitas pelayanan publik'
        ];
        return $descriptions[$category] ?? 'Fasilitas umum';
    }
    
    private function getCategoryName($category) {
        $names = [
            'masjid' => 'Masjid',
            'pendidikan' => 'Pendidikan',
            'kesehatan' => 'Kesehatan',
            'prasarana' => 'Prasarana Umum',
            'fasilitas' => 'Fasilitas Publik'
        ];
        return $names[$category] ?? 'Lainnya';
    }
    
    public function runMigration() {
        echo "🚀 Memulai Database Migration...\n\n";
        
        if ($this->createTables()) {
            if ($this->createDefaultAdmin()) {
                if ($this->migrateFacilitiesData()) {
                    echo "\n✅ Migration berhasil completed!\n";
                    echo "\n📋 Summary:\n";
                    echo "   - Database tables created\n";
                    echo "   - Default admin user created\n";
                    echo "   - Existing facilities data migrated\n";
                    echo "\n🔑 Login Admin:\n";
                    echo "   URL: /admin/login.html\n";
                    echo "   Email: admin@cirendeu.com\n";
                    echo "   Password: admin123\n";
                } else {
                    echo "\n❌ Migration failed at data migration step\n";
                }
            } else {
                echo "\n❌ Migration failed at admin creation step\n";
            }
        } else {
            echo "\n❌ Migration failed at table creation step\n";
        }
    }
}

// Run migration if script is called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $migration = new DatabaseMigration($pdo);
        $migration->runMigration();
        
    } catch (PDOException $e) {
        echo "❌ Database connection failed: " . $e->getMessage() . "\n";
        echo "\n📝 Pastikan:\n";
        echo "   1. MySQL server sudah running\n";
        echo "   2. Database sudah dibuat\n";
        echo "   3. Kredensial di config.php sudah benar\n";
    }
}
?>