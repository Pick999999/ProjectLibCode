/**
 * ============================================================================
 * Page Sender Utility (pageSender.js)
 * ============================================================================
 * ดึงข้อมูลโครงสร้าง HTML ทั้งหมดของหน้าปัจจุบัน บีบอัดเป็นไฟล์ Gzip (.gz)
 * และส่งไปที่ Server ปลายทางพร้อมข้อมูลชื่อไฟล์, URL และไฟล์ Zip ผ่าน FormData
 */
document.addEventListener('DOMContentLoaded', async () => {
    // 1. เตรียมข้อมูลหน้าเว็บ (Metadata)
    const fullUrl = window.location.href; // URL หรือ Path ทั้งหมด (เช่น file:///D:/Rust/derivAuth/testauth.html)
    const pathName = window.location.pathname; // Path ของไฟล์ (เช่น /D:/Rust/derivAuth/testauth.html)
    const fileName = pathName.substring(pathName.lastIndexOf('/') + 1) || 'index.html'; // ชื่อไฟล์ (เช่น testauth.html)

    // 2. ดึงข้อมูล HTML ทั้งหมดของหน้าเว็บ (รวม <head> และ <body>)
    const htmlContent = document.documentElement.outerHTML;

    // 3. ฟังก์ชันภายในสำหรับบีบอัดข้อความเป็น Gzip (ใช้ Native Browser API)
    async function compressToGzip(text) {
        const stream = new Blob([text]).stream();
        const compressedStream = stream.pipeThrough(new CompressionStream('gzip'));
        const response = new Response(compressedStream);
        return await response.blob();
    }

    // ฟังก์ชันสำหรับโหลด Script ไลบรารีภายนอกแบบ Dynamic
    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    try {
        // 4. โหลด html2canvas จาก CDN
        await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');

        // 5. ทำการแคปเจอร์หน้าจอ (ใช้ document.body หรือ element อื่นที่ต้องการ)
        const canvas = await html2canvas(document.body, {
            useCORS: true,       // รองรับการดึงภาพภายนอกแบบข้าม Domain
            allowTaint: true,
            logging: false       // ปิด log ของ html2canvas ใน console
        });

        // แปลงภาพ Canvas เป็น Blob (PNG)
        const screenshotBlob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

        // 6. ทำการบีบอัดข้อมูล HTML เป็น Gzip
        const gzipBlob = await compressToGzip(htmlContent);

        // 7. บรรจุข้อมูลใส่ FormData เพื่อส่งแบบ Multipart
        const formData = new FormData();
        formData.append('fileName', fileName);
        formData.append('path', pathName);
        formData.append('url', fullUrl);
        formData.append('zippedHtml', gzipBlob, fileName + '.gz'); // แนบไฟล์ zip ที่บีบอัดแล้ว

        if (screenshotBlob) {
            // แนบไฟล์รูปภาพหน้าจอไปด้วยในชื่อฟิลด์ 'screenshot'
            formData.append('screenshot', screenshotBlob, fileName + '.png');
        }

        // 8. ระบุ Host URL ปลายทางที่ต้องการส่งข้อมูลไปเก็บ
        const hostUrl = 'http://lovetoshopmall.com/Derivtrade2026/ajaxphp/savepagetolib.php'; // *** เปลี่ยนเป็น URL เซิร์ฟเวอร์จริงของคุณ ***

        // 9. ทำการส่ง AJAX (Fetch POST) ข้อมูลทั้งหมดไปยังเซิร์ฟเวอร์
        const response = await fetch(hostUrl, {
            method: 'POST',
            body: formData // เบราว์เซอร์จะตั้งค่า Header 'multipart/form-data' ให้อัตโนมัติ
        });

        if (response.ok) {
            console.log(`[PageSender] ส่งหน้าเว็บ ${fileName} และ Screenshot สำเร็จ`);
        } else {
            console.warn(`[PageSender] การส่งข้อมูลล้มเหลวด้วยสถานะ: ${response.status}`);
        }
    } catch (error) {
        console.error('[PageSender] เกิดข้อผิดพลาดในการประมวลผลหรือส่งข้อมูล:', error);
    }
});
