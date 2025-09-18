<?php

// ====================================================================
// SCRIPT OTOMASI IRYS CLI
// Skrip ini menyediakan menu interaktif untuk mengelola tugas Irys CLI.
// Dibuat untuk VPS Ubuntu, ditulis dalam PHP.
// ====================================================================

/**
 * Membersihkan layar terminal.
 */
function clearScreen(): void
{
    echo chr(27) . chr(91) . 'H' . chr(27) . chr(91) . 'J';
}

/**
 * Meminta input dari pengguna dan menyimpannya.
 *
 * @return array
 */
function getCredentials(): array
{
    // Cek apakah file konfigurasi ada
    $configFilePath = __DIR__ . '/irys_config.json';
    if (file_exists($configFilePath)) {
        $config = json_decode(file_get_contents($configFilePath), true);
        return $config;
    }
    
    // Jika tidak ada, kembalikan array kosong
    return [];
}

/**
 * Mendapatkan saldo wallet dari Irys CLI.
 *
 * @param array $credentials Kredensial wallet (privateKey dan walletAddress).
 * @return string Saldo wallet.
 */
function getWalletBalance(array $credentials): string
{
    if (empty($credentials['privateKey'])) {
        return "N/A";
    }

    $privateKey = escapeshellarg($credentials['privateKey']);
    $rpcUrl = 'https://sepolia.drpc.org';

    // Menggunakan privateKey untuk memeriksa saldo
    $command = "irys balance --from-key $privateKey -n devnet -t ethereum --provider-url $rpcUrl 2>&1";
    $output = shell_exec($command);
    
    // Cari angka saldo di output
    if (preg_match('/(\d+\.?\d*)\s*ETH/', $output, $matches)) {
        return $matches[0];
    }
    
    return "Gagal mendapatkan saldo";
}

/**
 * Menyimpan PRIVATE_KEY.
 */
function addPrivateKey(): void
{
    clearScreen();
    echo "--- Tambah PRIVATE KEY --- \n";
    $privateKey = readline("Masukkan PRIVATE KEY Anda: ");

    // Muat konfigurasi yang ada jika ada
    $configFilePath = __DIR__ . '/irys_config.json';
    $config = file_exists($configFilePath) ? json_decode(file_get_contents($configFilePath), true) : [];

    // Timpa atau tambahkan privateKey baru
    $config['privateKey'] = $privateKey;
    $config['rpcUrl'] = 'https://sepolia.drpc.org';
    
    // Hapus wallet address lama jika private key diganti
    if (isset($config['walletAddress']) && $config['walletAddress'] !== 'N/A') {
        unset($config['walletAddress']);
    }

    file_put_contents($configFilePath, json_encode($config, JSON_PRETTY_PRINT));
    
    echo "Konfigurasi telah disimpan. Silakan tambahkan Wallet Address secara terpisah.\n";
    readline("Tekan Enter untuk kembali ke menu...");
}

/**
 * Menambahkan WALLET ADDRESS secara manual.
 */
function addWalletAddress(): void
{
    clearScreen();
    echo "--- Tambah WALLET ADDRESS --- \n";
    $credentials = getCredentials();
    if (empty($credentials['privateKey'])) {
        echo "Anda harus menambahkan PRIVATE KEY terlebih dahulu.\n";
        readline("Tekan Enter untuk kembali ke menu...");
        return;
    }
    
    $walletAddress = readline("Masukkan WALLET ADDRESS Anda: ");
    $credentials['walletAddress'] = $walletAddress;
    
    // Simpan konfigurasi yang diperbarui
    file_put_contents(__DIR__ . '/irys_config.json', json_encode($credentials, JSON_PRETTY_PRINT));
    
    echo "Wallet Address berhasil disimpan.\n";
    readline("Tekan Enter untuk kembali ke menu...");
}


/**
 * Menjalankan perintah shell dan mencetak outputnya.
 *
 * @param string $command Perintah yang akan dijalankan.
 * @return string Output dari perintah yang dijalankan.
 */
function executeCommand(string $command): string
{
    echo "Menjalankan perintah: $command\n";
    return shell_exec($command);
}

/**
 * Mengunduh gambar dari Pexels API.
 *
 * @param string $apiKey API key Pexels.
 * @param int $count Jumlah gambar yang akan diunduh.
 * @param string $directory Direktori untuk menyimpan gambar.
 */
function downloadImagesFromPexels(string $apiKey, int $count, string $directory): void
{
    // Tambahkan instalasi ekstensi cURL PHP
    if (!function_exists('curl_init')) {
        echo "Ekstensi PHP cURL tidak terinstal. Menginstal sekarang...\n";
        shell_exec('sudo apt-get install php-curl -y');
        echo "Ekstensi cURL berhasil diinstal. Silakan jalankan ulang skrip.\n";
        readline("Tekan Enter untuk keluar...");
        exit(1);
    }
    
    // Hapus folder unduhan lama jika ada
    $existingFolders = glob('Images*', GLOB_ONLYDIR);
    foreach ($existingFolders as $folder) {
        $files = glob($folder . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($folder);
    }
    
    $downloadedCount = 0;
    $perPage = 15; // Pexels API secara default mengembalikan 15 gambar per permintaan.
    $url = "https://api.pexels.com/v1/curated?per_page=$perPage";
    
    $headers = [
        "Authorization: $apiKey",
    ];

    echo "Memulai pengunduhan $perPage gambar...\n";

    // Pastikan direktori ada
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
        
        // Simpan nama folder ke file konfigurasi setelah dibuat
        $configFilePath = __DIR__ . '/irys_config.json';
        $config = file_exists($configFilePath) ? json_decode(file_get_contents($configFilePath), true) : [];
        $config['lastDownloadFolder'] = $directory;
        file_put_contents($configFilePath, json_encode($config, JSON_PRETTY_PRINT));
    }


    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Error: Gagal terhubung ke Pexels API. Kode HTTP: $httpCode\n";
        echo "Response: " . ($response ?: "No response body.") . "\n";
        return;
    }

    $data = json_decode($response, true);
    if (empty($data['photos'])) {
        echo "Tidak ada gambar yang ditemukan dari API Pexels.\n";
        return;
    }

    foreach ($data['photos'] as $photo) {
        if ($downloadedCount >= $count) {
            break;
        }

        $imageUrl = $photo['src']['original'];
        
        // Periksa tipe konten dengan cURL sebelum mengunduh
        $ch_check = curl_init($imageUrl);
        curl_setopt($ch_check, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_check, CURLOPT_NOBODY, true);
        curl_setopt($ch_check, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch_check);
        $contentType = curl_getinfo($ch_check, CURLINFO_CONTENT_TYPE);
        curl_close($ch_check);

        $extension = '';
        if (strpos($contentType, 'image/jpeg') !== false) {
            $extension = 'jpg';
        } elseif (strpos($contentType, 'image/png') !== false) {
            $extension = 'png';
        }

        if ($extension) {
            $imageName = $photo['id'] . '.' . $extension;
            $filePath = "$directory/$imageName";
            
            // Periksa jika file sudah ada, lewati
            if (file_exists($filePath)) {
                echo "Melewatkan file duplikat: $imageName\n";
                continue;
            }

            $ch = curl_init($imageUrl);
            $fp = fopen($filePath, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
            echo "Mengunduh: $imageName\n";
            $downloadedCount++;
        }
    }
    echo "Selesai. Total gambar yang diunduh: $downloadedCount\n";
}

/**
 * Mengunggah satu gambar.
 *
 * @param string $filePath Jalur file gambar.
 * @param string $privateKey Kunci pribadi.
 */
function uploadImage(string $filePath, string $privateKey): void
{
    $privateKey = escapeshellarg($privateKey);
    $rpcUrl = 'https://sepolia.drpc.org';
    $fileName = escapeshellarg($filePath);
    $tagFileName = escapeshellarg(basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION)));
    $fileFormat = escapeshellarg(pathinfo($filePath, PATHINFO_EXTENSION));

    echo "Mengunggah file: " . basename($filePath) . "...\n";
    $command = "irys upload $fileName -n devnet -t ethereum -w $privateKey --tags $tagFileName $fileFormat --provider-url $rpcUrl --no-confirmation";
    $output = executeCommand($command);
    
    // Cari TX Hash atau URL di output
    if (preg_match('/Uploaded to (https:\/\/gateway\.irys\.xyz\/.*)/', $output, $matches)) {
        echo "Unggahan berhasil! Tautan transaksi:\n" . $matches[1] . "\n";
    } else {
        echo "Unggahan berhasil, tetapi tautan tidak terdeteksi. Silakan periksa output di atas.\n";
    }
}

/**
 * Mengunggah seluruh folder.
 *
 * @param string $folderPath Jalur folder.
 * @param string $privateKey Kunci pribadi.
 */
function uploadFolder(string $folderPath, string $privateKey): void
{
    $privateKey = escapeshellarg($privateKey);
    $rpcUrl = 'https://sepolia.drpc.org';
    $folderName = escapeshellarg($folderPath);

    echo "Mengunggah folder: " . basename($folderPath) . "...\n";
    $command = "irys upload-dir ./$folderName -n devnet -t ethereum -w $privateKey --provider-url $rpcUrl --no-confirmation";
    $output = executeCommand($command);
    
    // Cari manifest URL di output
    if (preg_match('/Manifest URL: (https:\/\/gateway\.irys\.xyz\/.*)/', $output, $matches)) {
        echo "Unggahan folder berhasil! Tautan manifest:\n" . $matches[1] . "\n";
    } else {
        echo "Unggahan berhasil, tetapi tautan manifest tidak terdeteksi. Silakan periksa output di atas.\n";
    }
}

/**
 * Fungsi utama untuk menjalankan mode otomatis.
 *
 * @param array $credentials Kredensial wallet.
 */
function startAutomation(array $credentials): void
{
    clearScreen();
    echo "===================================\n";
    echo "       MODE OTOMATIS IRYS CLI      \n";
    echo "===================================\n";
    echo "Mode otomatis telah dimulai. Skrip akan berjalan setiap jam.\n";
    echo "Tekan CTRL+C untuk menghentikan skrip.\n\n";

    $pexelApiKey = 'h7cGfvHSaSOyjrJSB5iod5gJx200Y31fKTx6X2uIfFg9nGT3HTFjLsPz';
    $runCount = 0;

    while (true) {
        $runCount++;
        echo "--- SIKLUS OTOMATIS KE-$runCount ---\n";
        
        // Unduh gambar setiap kali siklus dimulai
        $downloadDir = 'Images' . date('dM_H:i');
        downloadImagesFromPexels($pexelApiKey, 15, $downloadDir);
        
        // Cek tugas berdasarkan nomor siklus (genap/ganjil)
        if ($runCount % 2 != 0) {
            // Tugas ganjil: Unggah setiap gambar secara individual
            echo "\n--- UPLOADING GAMBAR SATU PER SATU (Siklus Ganjil) ---\n";
            $filesInFolder = glob($downloadDir . '/*.{jpg,jpeg,png}', GLOB_BRACE);
            foreach ($filesInFolder as $file) {
                uploadImage($file, $credentials['privateKey']);
            }
        } else {
            // Tugas genap: Unggah seluruh folder
            echo "\n--- UPLOADING SELURUH FOLDER (Siklus Genap) ---\n";
            uploadFolder($downloadDir, $credentials['privateKey']);
        }
        
        // Periksa saldo setelah setiap siklus
        echo "\nMemperbarui saldo Anda...\n";
        $balance = getWalletBalance($credentials);
        echo "Saldo saat ini: $balance\n";

        echo "\nSiklus ke-$runCount selesai. Menunggu 1 jam sebelum siklus berikutnya...\n";
        sleep(3600); // Jeda selama 1 jam (3600 detik)
    }
}

/**
 * Menampilkan menu utama dan menunggu input pengguna.
 */
function showMenu(): void
{
    while (true) {
        $credentials = getCredentials();
        $balance = "N/A";
        if (!empty($credentials['privateKey'])) {
            $balance = getWalletBalance($credentials);
        }

        clearScreen();
        echo "===================================\n";
        echo "       MENU IRYS CLI SCRIPT        \n";
        echo "===================================\n";

        // Tampilkan informasi wallet jika sudah ada
        if (!empty($credentials['privateKey'])) {
            echo "--- Konfigurasi Tersimpan ---\n";
            echo "Private Key   : " . ($credentials['privateKey'] ?? 'Tidak ditemukan') . "\n";
            echo "Wallet Address: " . ($credentials['walletAddress'] ?? 'Tidak ditemukan') . "\n";
            echo "Saldo         : " . $balance . "\n";
            echo "-------------------------------\n";
        }
        
        echo "1. INSTALASI AWAL (Node.js & Irys CLI)\n";
        echo "2. ADD PRIVATE KEY\n";
        echo "3. ADD WALLET ADDRESS\n";
        echo "4. FUND WALLET\n";
        echo "5. DOWNLOAD GAMBAR\n";
        echo "6. UPLOAD GAMBAR\n";
        echo "7. UPLOAD FOLDER\n";
        echo "8. MULAI OTOMATISASI\n";
        echo "9. Keluar\n";
        echo "===================================\n";
        $choice = readline("Masukkan pilihan Anda [1-9]: ");

        switch ($choice) {
            case '1':
                // Opsi 1: Instalasi Awal
                clearScreen();
                echo "--- Memulai Instalasi Awal ---\n";
                echo "Ini akan menginstal Node.js dan Irys CLI.\n";
                readline("Tekan Enter untuk melanjutkan...");
                
                echo "1/4: Memperbarui daftar paket...\n";
                executeCommand("sudo apt-get update && sudo apt-get upgrade -y");
                
                echo "2/4: Menginstal dependensi...\n";
                executeCommand("sudo apt install curl iptables build-essential git wget lz4 jq make protobuf-compiler cmake gcc nano automake autoconf tmux htop nvme-cli libgbm1 pkg-config libssl-dev libleveldb-dev tar clang bsdmainutils ncdu unzip libleveldb-dev screen ufw -y");
                
                echo "3/4: Menginstal Node.js versi 20...\n";
                executeCommand("curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs");
                
                echo "4/4: Menginstal Irys CLI...\n";
                executeCommand("sudo npm i -g @irys/cli");
                
                echo "Instalasi selesai. Anda sekarang bisa menggunakan Irys CLI.\n";
                readline("Tekan Enter untuk kembali ke menu...");
                break;

            case '2':
                // Opsi 2: Add Private Key
                addPrivateKey();
                break;

            case '3':
                // Opsi 3: Add Wallet Address
                addWalletAddress();
                break;

            case '4':
                // Opsi 4: Fund Wallet
                clearScreen();
                echo "--- Fund Wallet ---\n";
                $amount = readline("Masukkan jumlah dalam wei yang ingin di-fund: ");
                if (!is_numeric($amount) || $amount < 0) {
                    echo "Jumlah yang dimasukkan tidak valid.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                if (empty($credentials['privateKey'])) {
                    echo "PRIVATE_KEY tidak ditemukan. Silakan tambahkan terlebih dahulu melalui menu 'Add Private Key'.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                $privateKey = escapeshellarg($credentials['privateKey']);
                $rpcUrl = 'https://sepolia.drpc.org'; // RPC_URL otomatis

                echo "Memulai proses pendanaan...\n";
                $command = "irys fund $amount -n devnet -t ethereum -w $privateKey --provider-url $rpcUrl";
                executeCommand($command);
                
                echo "Perintah Fund Wallet telah dieksekusi.\n";
                echo "Memperbarui saldo Anda...\n";
                getWalletBalance($credentials);
                readline("Tekan Enter untuk kembali ke menu...");
                break;
                
            case '5':
                // Opsi 5: Download Gambar
                clearScreen();
                echo "--- Download Gambar ---\n";
                $pexelApiKey = 'h7cGfvHSaSOyjrJSB5iod5gJx200Y31fKTx6X2uIfFg9nGT3HTFjLsPz';
                $downloadDir = 'Images' . date('dM_H:i');
                downloadImagesFromPexels($pexelApiKey, 15, $downloadDir);
                readline("Tekan Enter untuk kembali ke menu...");
                break;

            case '6':
                // Opsi 6: Upload Gambar
                clearScreen();
                echo "--- Upload Gambar ---\n";
                $credentials = getCredentials();
                if (empty($credentials['privateKey'])) {
                    echo "PRIVATE_KEY tidak ditemukan. Silakan tambahkan terlebih dahulu melalui menu 'Add Private Key'.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                
                // Cari folder hasil unduhan
                $downloadFolders = glob('Images*', GLOB_ONLYDIR);
                if (empty($downloadFolders)) {
                    echo "Tidak ada folder hasil unduhan yang terdeteksi. Silakan unduh gambar terlebih dahulu.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                
                echo "Pilih folder yang berisi gambar yang ingin Anda unggah:\n";
                foreach ($downloadFolders as $index => $folder) {
                    echo ($index + 1) . ". $folder\n";
                }
                $folderChoice = readline("Masukkan nomor folder: ");
                $chosenFolder = $downloadFolders[$folderChoice - 1] ?? null;

                if (!$chosenFolder) {
                    echo "Pilihan tidak valid.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }

                // Cari file di dalam folder yang dipilih
                $filesInFolder = glob($chosenFolder . '/*.{jpg,jpeg,png}', GLOB_BRACE);
                if (empty($filesInFolder)) {
                    echo "Folder '$chosenFolder' tidak berisi gambar JPG atau PNG.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }

                echo "Pilih gambar yang ingin Anda unggah dari '$chosenFolder':\n";
                foreach ($filesInFolder as $index => $file) {
                    echo ($index + 1) . ". " . basename($file) . "\n";
                }
                $fileChoice = readline("Masukkan nomor gambar: ");
                $chosenFile = $filesInFolder[$fileChoice - 1] ?? null;

                if (!$chosenFile) {
                    echo "Pilihan tidak valid.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }

                uploadImage($chosenFile, $credentials['privateKey']);
                
                echo "\nPerintah Upload Gambar telah dieksekusi.\n";
                echo "Memperbarui saldo Anda...\n";
                getWalletBalance($credentials);
                readline("Tekan Enter untuk kembali ke menu...");
                break;
                
            case '7':
                // Opsi 7: Upload Folder
                clearScreen();
                echo "--- Upload Folder ---\n";
                $credentials = getCredentials();
                if (empty($credentials['privateKey'])) {
                    echo "PRIVATE_KEY tidak ditemukan. Silakan tambahkan terlebih dahulu melalui menu 'Add Private Key'.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                
                $downloadFolders = glob('Images*', GLOB_ONLYDIR);
                if (empty($downloadFolders)) {
                    echo "Tidak ada folder hasil unduhan yang terdeteksi.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                    break;
                }
                
                echo "Pilih folder yang ingin diunggah:\n";
                foreach ($downloadFolders as $index => $folder) {
                    echo ($index + 1) . ". $folder\n";
                }
                
                $choice = readline("Masukkan nomor folder yang ingin diunggah: ");
                $chosenFolder = $downloadFolders[$choice - 1] ?? null;

                if ($chosenFolder) {
                    $confirm = readline("Apakah Anda yakin ingin mengunggah folder '$chosenFolder'? (y/n): ");
                    if (strtolower($confirm) === 'y') {
                        uploadFolder($chosenFolder, $credentials['privateKey']);
                        
                        echo "\nPerintah Upload Folder telah dieksekusi.\n";
                        echo "Memperbarui saldo Anda...\n";
                        getWalletBalance($credentials);
                    } else {
                        echo "Pengunggahan dibatalkan.\n";
                    }
                } else {
                    echo "Pilihan tidak valid.\n";
                }

                readline("Tekan Enter untuk kembali ke menu...");
                break;
            
            case '8':
                // Opsi 8: Mulai Otomatisasi
                clearScreen();
                $credentials = getCredentials();
                if (empty($credentials['privateKey'])) {
                    echo "Anda harus menambahkan PRIVATE KEY terlebih dahulu untuk menjalankan otomatisasi.\n";
                    readline("Tekan Enter untuk kembali ke menu...");
                } else {
                    startAutomation($credentials);
                }
                break;

            case '9':
                // Opsi 9: Keluar
                echo "Keluar dari skrip. Terima kasih!\n";
                exit(0);
            
            default:
                // Pilihan tidak valid
                echo "Pilihan tidak valid. Silakan coba lagi.\n";
                readline("Tekan Enter untuk kembali ke menu...");
                break;
        }
    }
}

// Mulai skrip dengan memanggil fungsi menu utama
showMenu();

?>
