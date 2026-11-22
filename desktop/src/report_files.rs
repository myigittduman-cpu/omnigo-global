use base64::{engine::general_purpose::STANDARD, Engine};
use std::{io::Write, path::Path};

const MAX_BYTES: usize = 32 * 1024 * 1024;

pub fn decode_report(name: &str, encoded: &str) -> Result<(Vec<u8>, &'static str), String> {
    let delivery_name = ["Ankara", "Kırıkkale", "Kırşehir", "Nevşehir", "Kayseri", "Eskişehir"]
        .iter().any(|city| name == format!("{city} Teslimat Evrakları.pdf"));
    // Company exports keep their own name while paths and payload types remain restricted.
    let company_excel = name.ends_with(".xlsx") && name.len() > 5 && !name.starts_with('.') && name == name.trim() && !reserved_filename(name);
    let company_document = name.ends_with(".pdf") && !name.starts_with('.') && name == name.trim() && !reserved_filename(name)
        && [" Teslimat Evrakları.pdf", " Şehir Dışı Sevkiyat Listesi.pdf", " Sehir Disi Sevkiyat Listesi.pdf", " Sevkiyat Adresleri.pdf", " Tüm Şehirler Tüm Paneller.pdf", " Teslimat Evraklari.pdf", " Tum Sehirler Tum Paneller.pdf",
            " Şehir Dışı Ürünler.pdf", " Sehir Disi Urunler.pdf", " Out-of-City Products.pdf", " Produkte für auswärtige Lieferungen.pdf", " Produkte fur auswartige Lieferungen.pdf",
        ].iter().any(|suffix| name.ends_with(suffix))
        && name.split(' ').skip(1).any(download_date);
    let branded = name.split_once(" – ").is_some_and(|(brand, rest)| {
        !brand.is_empty() && !brand.starts_with('.') && brand.trim() == brand && !reserved_filename(name)
            && rest.split_once(' ').is_some_and(|(date, document)| download_date(date)
                && document.rsplit_once('.').is_some_and(|(title, _)| !title.trim().is_empty()))
    });
    if !(branded || name.starts_with("Omnigo-") || delivery_name || company_excel || company_document || payout_document(name)) || name.len() > 240 || name.chars().any(|c| c.is_control() || "<>:\"/\\|?*".contains(c)) {
        return Err("Rapor dosyasının adı geçersiz.".into());
    }
    let extension = if name.ends_with(".pdf") { "pdf" } else if name.ends_with(".xlsx") { "xlsx" } else if name.ends_with(".jpeg") { "jpeg" } else {
        return Err("Yalnızca PDF, Excel ve JPEG dosyaları kaydedilebilir.".into());
    };
    if encoded.len() > MAX_BYTES.div_ceil(3) * 4 { return Err("Rapor çok büyük. Daha kısa bir tarih aralığı seçin.".into()); }
    let bytes = STANDARD.decode(encoded).map_err(|_| "Rapor verisi okunamadı.")?;
    if bytes.len() > MAX_BYTES || bytes.len() < 8 || !(if extension == "pdf" { bytes.starts_with(b"%PDF-") } else if extension == "jpeg" { bytes.starts_with(&[0xff,0xd8,0xff]) && bytes.ends_with(&[0xff,0xd9]) } else { bytes.starts_with(b"PK\x03\x04") }) {
        return Err("Rapor dosyasının biçimi geçersiz.".into());
    }
    Ok((bytes, extension))
}

fn payout_document(name: &str) -> bool {
    if name.starts_with('.') || name != name.trim() || reserved_filename(name) {
        return false;
    }
    let Some(prefix) = name.strip_suffix(" Ödeme Tablosu.pdf").or_else(|| name.strip_suffix(" Odeme Tablosu.pdf")) else {
        return false;
    };
    let Some((company, range)) = prefix.rsplit_once(' ') else { return false; };
    if company.is_empty() || company != company.trim() {
        return false;
    }
    let Some((start, end)) = range.split_once('-') else { return false; };
    [start, end].into_iter().all(|date| {
        date.rsplit_once('.').is_some_and(|(day_month, year)| {
            year.len() == 2 && download_date(&format!("{day_month}.20{year}"))
        })
    })
}

fn download_date(value: &str) -> bool {
    let bytes = value.as_bytes();
    bytes.len() == 10 && bytes[2] == b'.' && bytes[5] == b'.'
        && bytes.iter().enumerate().all(|(index, byte)| index == 2 || index == 5 || byte.is_ascii_digit())
        && value[0..2].parse::<u8>().is_ok_and(|day| (1..=31).contains(&day))
        && value[3..5].parse::<u8>().is_ok_and(|month| (1..=12).contains(&month))
        && value[6..10].parse::<u16>().is_ok_and(|year| (2000..=9999).contains(&year))
}

fn reserved_filename(name: &str) -> bool {
    let stem = name.split('.').next().unwrap_or("").trim_end().to_ascii_uppercase();
    ["CON", "PRN", "AUX", "NUL"].contains(&stem.as_str())
        || (1..=9).any(|number| stem == format!("COM{number}") || stem == format!("LPT{number}"))
}

pub fn write_report(path: &Path, bytes: &[u8], extension: &str) -> Result<(), String> {
    if !path.extension().and_then(|e| e.to_str()).is_some_and(|e| e.eq_ignore_ascii_case(extension)) {
        return Err(format!("Dosyayı .{extension} uzantısıyla kaydedin."));
    }
    let parent = path.parent().ok_or("Kayıt klasörü geçersiz.")?;
    // Replace only after the complete file is safely written, preserving an existing file on error.
    let result = (|| -> std::io::Result<()> {
        let mut temporary = tempfile::NamedTempFile::new_in(parent)?;
        temporary.write_all(bytes)?;
        temporary.as_file().sync_all()?;
        temporary.persist(path).map_err(|e| e.error)?;
        Ok(())
    })();
    result.map_err(|_| "Dosya kaydedilemedi. Açık dosyayı kapatıp yazılabilir bir klasör seçerek yeniden deneyin.".into())
}

pub fn download_report(directory: &Path, name: &str, bytes: &[u8], extension: &str) -> Result<std::path::PathBuf, String> {
    let name_path=Path::new(name);
    if name_path.file_name().and_then(|part|part.to_str())!=Some(name)
        || name.contains(['/', '\\', ':']) || name_path.extension().and_then(|part|part.to_str())!=Some(extension) {
        return Err("Rapor dosyasının adı geçersiz.".into());
    }
    let stem=name_path.file_stem().and_then(|part|part.to_str()).ok_or("Rapor dosyasının adı geçersiz.")?;
    let result=(||->std::io::Result<std::path::PathBuf>{
        let mut temporary=tempfile::NamedTempFile::new_in(directory)?;
        temporary.write_all(bytes)?;temporary.as_file().sync_all()?;
        for index in 0..10000 {
            let filename=if index==0{name.to_string()}else{format!("{stem} ({index}).{extension}")};
            let target=directory.join(filename);
            match temporary.persist_noclobber(&target) {
                Ok(_)=>return Ok(target),
                Err(error) if error.error.kind()==std::io::ErrorKind::AlreadyExists=>temporary=error.file,
                Err(error)=>return Err(error.error),
            }
        }
        Err(std::io::Error::other("Aynı adlı dosya sınırı aşıldı."))
    })();
    result.map_err(|_| "Dosya İndirilenler klasörüne kaydedilemedi. Klasör erişimini ve boş alanı kontrol edin.".into())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn keeps_the_original_dated_payout_filename() {
        let data = STANDARD.encode(b"%PDF-1.7\n");
        for name in [
            "Vizyona 17.11.26-23.11.26 Ödeme Tablosu.pdf",
            "Vizyona 30.12.26-03.01.27 Odeme Tablosu.pdf",
        ] {
            assert!(decode_report(name, &data).is_ok(), "{name}");
        }
        for name in [
            "../Vizyona 17.11.26-23.11.26 Ödeme Tablosu.pdf",
            "Vizyona 17.13.26-23.11.26 Ödeme Tablosu.pdf",
            "Vizyona 17.11.26 Ödeme Tablosu.pdf",
            "Vizyona 17.11.26-23.11.26 Ödeme Tablosu.pdf.exe",
        ] {
            assert!(decode_report(name, &data).is_err(), "{name}");
        }
    }
    #[test]
    fn direct_download_preserves_existing_files_and_rejects_paths() {
        let directory=tempfile::tempdir().unwrap();
        let name="Kırıkkale 21.10.2026 Teslimat Evrakları.pdf";
        let first=download_report(directory.path(),name,b"%PDF-first","pdf").unwrap();
        let second=download_report(directory.path(),name,b"%PDF-second","pdf").unwrap();
        assert_eq!(std::fs::read(first).unwrap(),b"%PDF-first");
        assert_eq!(std::fs::read(&second).unwrap(),b"%PDF-second");
        assert_eq!(second.file_name().unwrap().to_str().unwrap(),"Kırıkkale 21.10.2026 Teslimat Evrakları (1).pdf");
        for invalid in ["../escape.pdf",r"folder\escape.pdf","C:escape.pdf","wrong.xlsx"] {
            assert!(download_report(directory.path(),invalid,b"%PDF-test","pdf").is_err());
        }
        assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(),2);
    }
    #[test]
    fn accepts_dated_company_documents_with_safe_names_and_pdf_bytes() {
        for name in ["Ankara 20.10.2026 Şehir Dışı Sevkiyat Listesi.pdf", "Tüm Şehirler 20.10.2026 Teslimat Evrakları.pdf", "Vizyona 19.10.2026 Ankara Hepsiburada Teslimat Evrakları.pdf", "Vizyona 19.10.2026 Tüm Şehirler n11 Sevkiyat Adresleri.pdf", "Yeni Firma 19.10.2026 Tüm Şehirler Tüm Paneller.pdf", "Vizyona 19.10.2026 Eskisehir Tum Paneller Teslimat Evraklari.pdf"] {
            assert!(decode_report(name, &STANDARD.encode(b"%PDF-1.7\n")).is_ok(), "{name}");
            assert!(decode_report(name, &STANDARD.encode(b"MZunsafe")).is_err());
        }
        for name in ["../Vizyona 19.10.2026 Ankara n11 Teslimat Evrakları.pdf", "Vizyona 19.10.2026 Ankara n11 Teslimat Evrakları.pdf.exe", "Vizyona 00.13.2026 Ankara n11 Teslimat Evrakları.pdf", "Vizyona Ankara n11 Teslimat Evrakları.pdf"] {
            assert!(decode_report(name, &STANDARD.encode(b"%PDF-1.7\n")).is_err(), "{name}");
        }
        for suffix in ["Şehir Dışı Ürünler", "Sehir Disi Urunler", "Out-of-City Products", "Produkte für auswärtige Lieferungen", "Produkte fur auswartige Lieferungen"] {
            let filename = format!("Örnek Firma Ankara 21.11.2026 {suffix}.pdf");
            assert!(decode_report(&filename, &STANDARD.encode(b"%PDF-1.7\n")).is_ok());
            assert!(decode_report(&filename.replace("21.11.2026", "00.00.2026"), &STANDARD.encode(b"%PDF-1.7\n")).is_err());
        }
        assert!(!download_date("ğğ.ğğ.ğğ"));
    }
    #[test]
    fn accepts_company_excel_without_relaxing_file_safety() {
        for name in ["Vizyon Beyaz Eşya-Tüm şehirler Hepsiburada 2026-10 Sevkiyatlar.xlsx", "Yeni Firma-Satış Raporu.xlsx"] {
            assert!(decode_report(name, &STANDARD.encode(b"PK\x03\x04test")).is_ok());
            assert!(decode_report(name, &STANDARD.encode(b"MZunsafe")).is_err());
        }
        for name in ["../Firma.xlsx", "Firma\\test.xlsx", "Firma:stream.xlsx", ".Firma.xlsx", "CON.xlsx", "LPT1.xlsx", "COM9.xlsx", "Firma.xlsx.exe", " Firma.xlsx"] {
            assert!(decode_report(name, &STANDARD.encode(b"PK\x03\x04test")).is_err());
        }
        assert!(decode_report("Firma.pdf", &STANDARD.encode(b"%PDF-1.7\n")).is_err());
    }
    #[test]
    fn accepts_jpeg_and_rejects_disguised_files() {
        assert!(decode_report("Omnigo-Fatura-1.jpeg", &STANDARD.encode([0xff,0xd8,0xff,0xe0,0x00,0x00,0xff,0xd9])).is_ok());
        assert!(decode_report("Omnigo-Fatura-1.jpeg", &STANDARD.encode(b"%PDF-1.7\n")).is_err());
    }
    #[test]
    fn validates_report_type_size_and_filename() {
        for (name, data) in [("Omnigo-Mağaza.pdf", b"%PDF-1.7\n".as_slice()), ("Omnigo-Mağaza.xlsx", b"PK\x03\x04test".as_slice())] {
            assert_eq!(decode_report(name, &STANDARD.encode(data)).unwrap().0, data);
        }
        for name in ["../Omnigo-test.pdf", "Omnigo-a.exe", "Omnigo-C:test.pdf", "Omnigo-\ntest.pdf"] {
            assert!(decode_report(name, &STANDARD.encode(b"%PDF-1.7\n")).is_err());
        }
        for city in ["Ankara", "Kırıkkale", "Kırşehir", "Nevşehir", "Kayseri", "Eskişehir"] {
            assert!(decode_report(&format!("{city} Teslimat Evrakları.pdf"), &STANDARD.encode(b"%PDF-1.7\n")).is_ok());
        }
        for name in ["../Eskişehir Teslimat Evrakları.pdf", "Eskişehir Teslimat Evrakları.pdf.exe", "Rastgele.pdf", "CON.pdf"] {
            assert!(decode_report(name, &STANDARD.encode(b"%PDF-1.7\n")).is_err());
        }
        assert!(decode_report("Omnigo-test.pdf", &STANDARD.encode(b"MZbadexe")).is_err());
        assert!(decode_report("Omnigo-test.xlsx", "not base64").is_err());
        assert!(decode_report("Omnigo-test.pdf", &"A".repeat(MAX_BYTES.div_ceil(3)*4+4)).is_err());
    }
    #[test]
    fn writes_exact_bytes_and_preserves_file_on_rejected_extension() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("Türkçe rapor.pdf");
        write_report(&target, b"%PDF-1.7\nfirst", "pdf").unwrap();
        write_report(&target, b"%PDF-1.7\nupdated", "pdf").unwrap();
        assert!(write_report(&target, b"bad", "xlsx").is_err());
        assert_eq!(std::fs::read(target).unwrap(), b"%PDF-1.7\nupdated");
        assert!(write_report(&dir.path().join("missing/report.pdf"), b"%PDF-1.7", "pdf").is_err());
    }
}
