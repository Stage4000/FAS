/* Real Chromium and the shipped reader, with only synthetic loopback routes. */
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
  const [base,id,size]=process.argv.slice(2);
  assert.match(base,/^http:\/\/127\.0\.0\.1:\d+$/);
  assert.match(id,/^[1-9]\d*$/);
  const browser=await chromium.launch({
    ...(process.env.FAS_TEST_CHROMIUM ? {executablePath:process.env.FAS_TEST_CHROMIUM} : {}),
    args:['--disable-background-networking'],
  });
  try {
    const context=await browser.newContext({viewport:{width:390,height:844}});
    await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort('blockedbyclient'));
    const page=await context.newPage(), responses=[];
    page.on('response',r=>{if(new URL(r.url()).pathname==='/admin/manuals-file.php')responses.push(r);});
    assert.equal((await context.request.post(base+'/fixture-login.php')).status(),200);
    const start=Date.now();
    assert.equal((await page.goto(base+'/admin/manuals-preview.php?id='+id)).status(),200);
    assert.equal(await page.locator('[data-manual-reader]').getAttribute('data-pdf-ranges'),'on');
    const ready=async n=>{
      await page.waitForFunction(n=>document.querySelector('[data-status]')?.textContent==='Page '+n+' of 2',n);
      assert(await page.locator('[data-paper]:not([hidden]) canvas').evaluate(c=>c.width>1&&c.height>1));
    };
    await ready(1);
    const firstMs=Date.now()-start;
    await page.getByRole('button',{name:'Next page'}).click();
    await ready(2);
    let rangeBytes=0;
    for(const r of responses.filter(r=>r.status()===206)){
      const h=r.headers();
      assert.match(h['content-range'],new RegExp('^bytes \\d+-\\d+/'+size+'$'));
      assert.match(h['cache-control'],/no-store/);
      assert.equal(h['content-encoding'],undefined);
      rangeBytes+=Number(h['content-length']);
    }
    const completedBytes=await page.evaluate(()=>performance.getEntriesByType('resource')
      .filter(r=>r.name.includes('/admin/manuals-file.php')).reduce((n,r)=>n+r.encodedBodySize,0));
    assert(rangeBytes>0&&rangeBytes<8*1024*1024,'the browser must use bounded partial responses');
    assert(completedBytes>0&&completedBytes<16*1024*1024,'completed PDF transfers must remain bounded');
    console.log(JSON.stringify({pages:[1,2],fixtureBytes:Number(size),rangeBytes,completedBytes,firstMs}));
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
