(() => {
    'use strict';
    if (typeof document === 'undefined') return;
    const NS = 'http://www.w3.org/2000/svg';
    const definitions = {load:[['cpu_percent','CPU','#818cf8'],['memory_percent','RAM','#22c55e']],disk:[['disk_percent','Disk','#38bdf8']],network:[['net_rx_bytes_per_second','RX','#818cf8'],['net_tx_bytes_per_second','TX','#22c55e']],io:[['disk_read_bytes_per_second','Read','#818cf8'],['disk_write_bytes_per_second','Write','#22c55e']]};
    const node = (name, attrs, value) => { const el = document.createElementNS(NS,name); for (const [k,v] of Object.entries(attrs || {})) el.setAttribute(k,String(v)); if (value !== undefined) el.textContent=value; return el; };
    document.querySelectorAll('[data-monitor-history]').forEach(root => {
        const form=root.querySelector('form'), select=form.elements.namedItem('hours'), status=root.querySelector('[data-monitor-status]');
        let controller,generation=0,refreshTimer;
        const date = time => new Intl.DateTimeFormat(root.dataset.locale,{dateStyle:'short',timeStyle:'short',timeZone:root.dataset.timezone}).format(new Date(time*1000));
        function number(value,percent) {
            if (value >= 1e15) return value.toExponential(2)+(percent?' %':' B/s');
            if (percent) return new Intl.NumberFormat(root.dataset.locale,{maximumFractionDigits:1}).format(value)+' %';
            const units=['B/s','KiB/s','MiB/s','GiB/s'];let index=0;while(value>=1024&&index<3){value/=1024;index++;}
            return new Intl.NumberFormat(root.dataset.locale,{maximumFractionDigits:1}).format(value)+' '+units[index];
        }
        function draw(payload) {
            if (!payload || !Array.isArray(payload.points) || payload.points.length>1200 || !Number.isFinite(payload.from) || !Number.isFinite(payload.to) || payload.to<=payload.from) throw new Error('Invalid monitoring data');
            for (const [chart,lines] of Object.entries(definitions)) {
                const svg=root.querySelector('[data-monitor-chart='+chart+']');svg.replaceChildren();
                const percent=chart==='load'||chart==='disk', values=payload.points.flatMap(p=>lines.map(([field])=>p.metrics?.[field]?.average)).filter(Number.isFinite), maximum=Math.max(percent?100:1,...values);
                for (let i=0;i<3;i++){const y=20+i*65;svg.append(node('line',{x1:70,x2:620,y1:y,y2:y,stroke:'#94a3b8','stroke-opacity':'.2'}),node('text',{x:65,y:y+4,'text-anchor':'end'},number(maximum*(1-i/2),percent)));}
                const legend=[];
                for (const [field,label,color] of lines) {
                    const points=payload.points.filter(p=>Number.isFinite(p.timestamp)&&Number.isFinite(p.metrics?.[field]?.average)).sort((a,b)=>a.timestamp-b.timestamp);let path='',last=null;
                    for(const point of points){const value=point.metrics[field].average;if(value<0||point.timestamp<payload.from||point.timestamp>payload.to)continue;const x=70+(point.timestamp-payload.from)/(payload.to-payload.from)*550,y=150-value/maximum*130;path+=(last&&point.timestamp-last.timestamp<=payload.resolution_seconds*2?'L':'M')+x.toFixed(2)+' '+y.toFixed(2);const circle=node('circle',{cx:x,cy:y,r:2,fill:color});circle.append(node('title',{},label+' · '+date(point.last_observed_at)+' · '+number(value,percent)));svg.append(circle);last=point;}
                    if(path)svg.append(node('path',{d:path,fill:'none',stroke:color,'stroke-width':2}));
                    legend.push(label+': '+(last?number(last.metrics[field].average,percent):'—'));
                }
                svg.append(node('text',{x:70,y:190},date(payload.from)),node('text',{x:620,y:190,'text-anchor':'end'},date(payload.to)));
                root.querySelector('[data-monitor-legend='+chart+']').textContent=legend.join(' · ');
            }
            const sources=[...new Set(payload.points.flatMap(p=>Array.isArray(p.sources)?p.sources:[]))];
            root.querySelector('[data-monitor-summary]').textContent=date(payload.from)+' – '+date(payload.to)+' · '+root.dataset.resolution+': '+payload.resolution_seconds+' s · '+root.dataset.source+': '+sources.join(', ');
            status.textContent=payload.points.length?'':root.dataset.empty;
        }
        function query() {
            const url=new URL(location.href);url.search='';
            if(select.value==='custom'){for(const field of ['from','to']){const time=new Date(form.elements.namedItem(field).value);if(!Number.isFinite(time.getTime()))throw new Error(root.dataset.failed);url.searchParams.set(field,time.toISOString());}}
            else url.searchParams.set('hours',select.value);
            return url;
        }
        function schedule(){
            clearTimeout(refreshTimer);
            // A fixed custom interval never changes; a year does not need minute polling.
            if(select.value==='custom')return;
            const hours=Number(select.value),delay=hours<=24?60000:hours<=168?300000:hours<=744?900000:3600000;
            refreshTimer=setTimeout(()=>{if(document.hidden)schedule();else load();},delay);
        }
        async function load(){
            clearTimeout(refreshTimer);controller?.abort();
            const activeController=new AbortController();controller=activeController;
            const current=++generation,timeout=setTimeout(()=>activeController.abort(),45000);
            status.textContent=root.dataset.loading;root.setAttribute('aria-busy','true');
            try{
                const url=query(),csv=new URL(url);csv.searchParams.set('format','csv');root.querySelector('[data-monitor-export]').href=csv.href;url.searchParams.set('format','json');
                const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',signal:activeController.signal});
                if(!response.ok)throw new Error(root.dataset.failed);
                const payload=await response.json();if(current===generation)draw(payload);
            }catch(_){if(current===generation)status.textContent=root.dataset.failed;}
            finally{clearTimeout(timeout);if(current===generation){root.removeAttribute('aria-busy');schedule();}}
        }
        select.addEventListener('change',()=>{root.querySelectorAll('[data-monitor-custom]').forEach(el=>{el.hidden=select.value!=='custom';});});
        form.addEventListener('submit',event=>{event.preventDefault();load();});
        const existing=new URL(location.href).searchParams.get('hours');if([...select.options].some(o=>o.value===existing))select.value=existing;
        load();
    });
})();
