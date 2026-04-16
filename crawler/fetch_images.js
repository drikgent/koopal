const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const seriesUrl = process.argv[2];

if (!seriesUrl) {
    console.error("❌ Please provide a series URL");
    process.exit(1);
}

(async () => {
    const browser = await puppeteer.launch({
        headless: true,
        executablePath: "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();

    await page.setUserAgent(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36"
    );

    console.log("🔍 Opening series page...");
    await page.goto(seriesUrl, { waitUntil: 'networkidle2' });

    // 🔎 Collect chapter links
    const chapters = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('a'))
            .map(a => a.href)
            .filter(href => href.includes('/chapter-'));
    });

    if (chapters.length === 0) {
        console.error("❌ No chapters found");
        await browser.close();
        process.exit(1);
    }

    console.log(`📘 Found ${chapters.length} chapters`);

    // 📥 Process chapters one by one
    for (const chapterUrl of chapters) {
        const chapterName = chapterUrl.split('/').filter(Boolean).pop();
        const chapterFolder = path.join(__dirname, chapterName);

        if (!fs.existsSync(chapterFolder)) {
            fs.mkdirSync(chapterFolder);
        }

        console.log(`\n⬇ Downloading ${chapterName}`);

        const chapterPage = await browser.newPage();

        chapterPage.on('response', async (response) => {
            const headers = response.headers();
            if (headers['content-type'] && headers['content-type'].includes('application/zip')) {
                const buffer = await response.buffer();
                const zipPath = path.join(chapterFolder, 'chapter.zip');
                fs.writeFileSync(zipPath, buffer);
                console.log(`✅ ZIP saved: ${zipPath}`);
            }
        });

        try {
            await chapterPage.goto(chapterUrl, { waitUntil: 'networkidle2' });
            await new Promise(r => setTimeout(r, 4000));
        } catch (err) {
            console.error(`⚠ Failed: ${chapterUrl}`);
        }

        await chapterPage.close();

        const zipPath = path.join(chapterFolder, 'chapter.zip');

if (fs.existsSync(zipPath)) {
    console.log(`⏭ Skipping ${chapterName} (already downloaded)`);
    continue;
}

    }

    await browser.close();
    console.log("\n🎉 ALL CHAPTERS DOWNLOADED");
})();
