<?php
/**
 * MCP Apps UI templates (official MCP "Apps" extension).
 *
 * Implements the standardized interactive-UI pattern shared by Claude, ChatGPT,
 * Goose, VS Code, Postman, MCPJam, … (https://modelcontextprotocol.io/docs/extensions/apps).
 *
 * The pattern (3 parts):
 *  1. A tool declares `_meta.ui.resourceUri` → a `ui://` template (plus the
 *     ChatGPT compatibility alias `_meta["openai/outputTemplate"]`). Added to
 *     the tool descriptor in `tools/list` and echoed on the `tools/call` result.
 *  2. The server serves that template via `resources/list` + `resources/read`
 *     with MIME `text/html;profile=mcp-app`.
 *  3. The template is STATIC HTML+JS. The host renders it in a sandboxed iframe
 *     and pushes the live tool result over the postMessage bridge
 *     (`ui/notifications/tool-result` → `params.structuredContent`, or ChatGPT's
 *     `window.openai.toolOutput`). The JS renders the card/chart client-side.
 *
 * Why client-side JS (not PHP-rendered HTML)? Because the standard separates the
 * static template (cached/preloaded by the host) from the dynamic data (the tool
 * result delivered later over the bridge). The same template renders every call.
 *
 * The templates are fully self-contained: inline CSS + inline JS + inline SVG,
 * no external scripts/fonts/CDNs (the sandbox blocks them by default).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of MCP Apps UI templates and the resource endpoints that serve them.
 */
class MCP_UI {

	/** `ui://` URI prefix for our templates. */
	const URI_PREFIX = 'ui://llmagnet/';

	/** MCP Apps UI MIME type. Hosts only enable the UI bridge for this type. */
	const MIME = 'text/html;profile=mcp-app';

	/**
	 * Template version. Bump whenever the HTML/JS below changes so hosts
	 * (which cache by URI) load the new bundle instead of a stale one.
	 */
	const TEMPLATE_VERSION = '2';

	/**
	 * Tool ids that have a UI template.
	 *
	 * @return string[]
	 */
	private static function ui_tool_ids() {
		return [
			'get_visibility_score',
			'get_bot_traffic',
			'get_ai_visit_trends',
			'get_recommendations',
		];
	}

	/**
	 * Whether a tool has a UI template.
	 *
	 * @param string $tool Tool id.
	 * @return bool
	 */
	public static function has_ui( $tool ) {
		return in_array( $tool, self::ui_tool_ids(), true );
	}

	/**
	 * Versioned `ui://` URI for a tool's template.
	 *
	 * @param string $tool Tool id.
	 * @return string
	 */
	public static function resource_uri( $tool ) {
		return self::URI_PREFIX . $tool . '.v' . self::TEMPLATE_VERSION . '.html';
	}

	/**
	 * `_meta` to attach to a tool (descriptor in tools/list AND the tools/call
	 * result) so MCP Apps hosts link the tool to its UI template.
	 *
	 * @param string $tool Tool id.
	 * @return array|null
	 */
	public static function tool_meta( $tool ) {
		if ( ! self::has_ui( $tool ) ) {
			return null;
		}
		$uri = self::resource_uri( $tool );
		return [
			// MCP Apps standard.
			'ui'                              => [
				'resourceUri' => $uri,
				'visibility'  => [ 'model', 'app' ],
			],
			// ChatGPT (OpenAI Apps SDK) compatibility aliases.
			'openai/outputTemplate'           => $uri,
			'openai/toolInvocation/invoking'  => 'Rendering…',
			'openai/toolInvocation/invoked'   => 'Rendered.',
		];
	}

	// ── Resource endpoints (resources/list, resources/read) ────────────────────

	/**
	 * Resource descriptors for `resources/list`.
	 *
	 * @return array[]
	 */
	public static function list_resources() {
		$out = [];
		foreach ( self::ui_tool_ids() as $tool ) {
			$out[] = [
				'uri'         => self::resource_uri( $tool ),
				'name'        => $tool . '_ui',
				'title'       => self::title_for( $tool ),
				'description' => sprintf( 'Interactive UI template rendered for the %s tool result.', $tool ),
				'mimeType'    => self::MIME,
				'_meta'       => [ 'ui' => [ 'prefersBorder' => true ] ],
			];
		}
		return $out;
	}

	/**
	 * Resource contents for `resources/read`.
	 *
	 * @param string $uri Requested resource URI.
	 * @return array|null `{ contents: [...] }` or null when the URI is unknown.
	 */
	public static function read_resource( $uri ) {
		foreach ( self::ui_tool_ids() as $tool ) {
			if ( self::resource_uri( $tool ) === $uri ) {
				return [
					'contents' => [ [
						'uri'      => $uri,
						'mimeType' => self::MIME,
						'text'     => self::template_html( $tool ),
						'_meta'    => [ 'ui' => [ 'prefersBorder' => true ] ],
					] ],
				];
			}
		}
		return null;
	}

	// ── Template assembly ───────────────────────────────────────────────────────

	/**
	 * Human title for a tool's UI.
	 *
	 * @param string $tool Tool id.
	 * @return string
	 */
	private static function title_for( $tool ) {
		switch ( $tool ) {
			case 'get_visibility_score':
				return __( 'AI Visibility Score', 'llmagnet-llm-txt-generator' );
			case 'get_bot_traffic':
				return __( 'AI Bot Traffic', 'llmagnet-llm-txt-generator' );
			case 'get_ai_visit_trends':
				return __( 'AI Visit Trends', 'llmagnet-llm-txt-generator' );
			case 'get_recommendations':
				return __( 'AI Visibility Recommendations', 'llmagnet-llm-txt-generator' );
		}
		return $tool;
	}

	/**
	 * Full self-contained HTML document for a tool's template.
	 *
	 * @param string $tool Tool id.
	 * @return string
	 */
	private static function template_html( $tool ) {
		$css = self::css();
		$js  = str_replace( '__TOOL__', $tool, self::runtime_js() );

		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( self::title_for( $tool ) ) . '</title>'
			. '<style>' . $css . '</style></head><body>'
			. '<div id="root"></div>'
			. '<script>' . $js . '</script>'
			. '</body></html>';
	}

	/**
	 * Shared stylesheet for every template.
	 *
	 * @return string
	 */
	private static function css() {
		return '
			*{box-sizing:border-box}
			body{margin:0;padding:16px;background:#f4f5f8;color:#1c2024;
				font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
			.card{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:20px;
				box-shadow:0 1px 2px rgba(16,24,40,.04);max-width:720px;margin:0 auto}
			.head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
			.kicker{font-size:16px;font-weight:700}
			.sub{font-size:12px;color:#8a90a2}
			.pill{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}
			.score-grid{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
			.gauge-wrap{flex:0 0 auto}
			.kpis{display:flex;gap:24px;flex-wrap:wrap}
			.kpis.row{margin-bottom:12px}
			.kpi-val{font-size:22px;font-weight:700}
			.kpi-lbl{font-size:12px;color:#8a90a2;margin-top:2px}
			.bars-title{font-size:12px;font-weight:600;color:#8a90a2;text-transform:uppercase;
				letter-spacing:.04em;margin:18px 0 10px}
			.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:9px}
			.bar-label{flex:0 0 120px;font-size:13px;color:#3a4150;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.bar-track{flex:1;height:8px;background:#eef0f4;border-radius:999px;overflow:hidden}
			.bar-fill{height:100%;border-radius:999px;transition:width .3s}
			.bar-val{flex:0 0 44px;text-align:right;font-size:13px;font-weight:600;color:#3a4150}
			.chart{margin:8px 0 4px}
			.empty-chart{padding:40px 0;text-align:center;color:#8a90a2;font-size:13px}
			.recs{list-style:none;margin:0;padding:0}
			.rec{display:flex;gap:12px;padding:12px 0;border-top:1px solid #eef0f4}
			.rec:first-child{border-top:0}
			.rec.empty{color:#8a90a2;justify-content:center}
			.rec-area{font-size:11px;font-weight:700;color:#8a90a2;text-transform:uppercase;letter-spacing:.04em}
			.rec-msg{font-size:14px;margin-top:2px}
			.rec-action{font-size:13px;color:#4f46e5;margin-top:4px}
			.rec-fix{margin-top:8px}
			.fix-btn{appearance:none;cursor:pointer;border:0;border-radius:8px;background:#4f46e5;color:#fff;
				font:600 12px/1 inherit;padding:7px 14px;transition:background .15s}
			.fix-btn:hover{background:#4338ca}
			.badge{flex:0 0 auto;align-self:flex-start;font-size:11px;font-weight:700;
				padding:3px 9px;border-radius:999px;height:fit-content}
			.p-critical{background:#feebec;color:#ce2c31}
			.p-high{background:#fff1e7;color:#cf5c19}
			.p-medium{background:#fff7e6;color:#a86d00}
			.p-low{background:#e9f7ef;color:#1b7a47}
		';
	}

	/**
	 * The client-side runtime: MCP Apps bridge + renderers. `__TOOL__` is
	 * replaced with the concrete tool id at serve time.
	 *
	 * @return string
	 */
	private static function runtime_js() {
		return <<<'JS'
(function(){
  var TOOL = '__TOOL__';
  var root = document.getElementById('root');
  var rendered = false;

  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function num(n){ n=Number(n); if(!isFinite(n)) n=0; return n.toLocaleString(); }
  function scoreColor(v){ v=Number(v)||0; if(v<30) return '#e5484d'; if(v<60) return '#f5a623'; return '#30a46c'; }
  function grade(v){ v=Number(v)||0; if(v<30) return 'Poor'; if(v<60) return 'Fair'; if(v<80) return 'Good'; return 'Excellent'; }
  function shortDate(d){ var t=new Date(d); if(isNaN(t.getTime())) return String(d); return t.toLocaleDateString(undefined,{month:'short',day:'numeric'}); }
  var PAL=['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ec4899','#8b5cf6'];

  function kpi(val,label){ return '<div class="kpi"><div class="kpi-val">'+val+'</div><div class="kpi-lbl">'+esc(label)+'</div></div>'; }
  function bar(label,pct,valDisplay,color){ return '<div class="bar-row"><div class="bar-label">'+esc(label)+'</div><div class="bar-track"><div class="bar-fill" style="width:'+pct+'%;background:'+color+'"></div></div><div class="bar-val">'+valDisplay+'</div></div>'; }

  function gauge(score){
    score=Math.max(0,Math.min(100,Number(score)||0));
    var r=64,circ=2*Math.PI*r,off=circ*(1-score/100),col=scoreColor(score);
    return '<svg viewBox="0 0 160 160" width="160" height="160" role="img" aria-label="Score '+score+' of 100">'
      +'<circle cx="80" cy="80" r="64" fill="none" stroke="#e6e8ee" stroke-width="12"/>'
      +'<circle cx="80" cy="80" r="64" fill="none" stroke="'+col+'" stroke-width="12" stroke-linecap="round" stroke-dasharray="'+circ.toFixed(2)+'" stroke-dashoffset="'+off.toFixed(2)+'" transform="rotate(-90 80 80)"/>'
      +'<text x="80" y="80" text-anchor="middle" dominant-baseline="central" font-size="40" font-weight="700" fill="'+col+'">'+score+'</text>'
      +'<text x="80" y="106" text-anchor="middle" font-size="13" fill="#8a90a2">/ 100</text></svg>';
  }

  function pointsByDay(rows){
    rows=rows||[]; var by={};
    rows.forEach(function(rw){ if(!rw||rw.date==null) return; var d=String(rw.date); by[d]=(by[d]||0)+(Number(rw.visits)||0); });
    return Object.keys(by).sort().map(function(d){ return {label:d,value:by[d]}; });
  }

  function botTotals(rows){
    var out=[];
    if(Array.isArray(rows)){ rows.forEach(function(rw){ if(rw&&typeof rw==='object'){ out.push({label: rw.bot_name||'Unknown', value: Number(rw.total_visits!=null?rw.total_visits:(rw.count||0))||0}); } }); }
    else if(rows&&typeof rows==='object'){ Object.keys(rows).forEach(function(k){ var v=rows[k]; out.push({label:k, value:(v&&typeof v==='object')?Number(v.count!=null?v.count:(v.total_visits||0))||0:Number(v)||0}); }); }
    return out;
  }

  function lineChart(points){
    var w=700,h=240,pl=40,pr=16,pt=16,pb=30,pw=w-pl-pr,ph=h-pt-pb,n=points.length;
    if(!n) return '<div class="empty-chart">No visit data yet for this window.</div>';
    var max=1; points.forEach(function(p){ max=Math.max(max,Number(p.value)||0); });
    function X(i){ return n<=1 ? pl+pw/2 : pl+pw*i/(n-1); }
    function Y(v){ return pt+ph*(1-(v/max)); }
    var pts=[],dots='';
    points.forEach(function(p,i){ var x=X(i),y=Y(Number(p.value)||0); pts.push(x.toFixed(1)+','+y.toFixed(1)); dots+='<circle cx="'+x.toFixed(1)+'" cy="'+y.toFixed(1)+'" r="3" fill="#4f46e5"><title>'+esc(shortDate(p.label))+': '+num(p.value)+'</title></circle>'; });
    var poly=pts.join(' '), base=pt+ph;
    var area=X(0).toFixed(1)+','+base.toFixed(1)+' '+poly+' '+X(n-1).toFixed(1)+','+base.toFixed(1);
    var idxs=[0,Math.floor((n-1)/2),n-1].filter(function(v,i,a){return a.indexOf(v)===i;});
    var xl=''; idxs.forEach(function(i){ xl+='<text x="'+X(i).toFixed(1)+'" y="'+(h-8)+'" text-anchor="middle" font-size="11" fill="#8a90a2">'+esc(shortDate(points[i].label))+'</text>'; });
    return '<svg viewBox="0 0 '+w+' '+h+'" width="100%" preserveAspectRatio="xMidYMid meet" role="img">'
      +'<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4f46e5" stop-opacity="0.28"/><stop offset="100%" stop-color="#4f46e5" stop-opacity="0"/></linearGradient></defs>'
      +'<line x1="'+pl+'" y1="'+base.toFixed(1)+'" x2="'+(w-pr)+'" y2="'+base.toFixed(1)+'" stroke="#e6e8ee" stroke-width="1"/>'
      +'<text x="'+pl+'" y="'+(pt+4)+'" text-anchor="end" font-size="11" fill="#8a90a2">'+num(max)+'</text>'
      +'<polygon points="'+area+'" fill="url(#g)"/>'
      +'<polyline points="'+poly+'" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>'
      +dots+xl+'</svg>';
  }

  var R = {
    get_visibility_score: function(d){
      var score=Number(d.score)||0;
      var labels={frequency:'Crawl frequency',visit_type:'Visit type',visit_count:'Visit volume',page_status:'Page status',url_type:'URL coverage'};
      var bars='';
      if(d.breakdown&&typeof d.breakdown==='object'){ Object.keys(d.breakdown).forEach(function(k){ var c=d.breakdown[k]||{}; var v=Math.max(0,Math.min(100,Math.round(Number(c.score)||0))); bars+=bar(labels[k]||k,v,String(v),scoreColor(v)); }); }
      var html='<div class="card"><div class="head"><span class="kicker">AI Visibility Score</span><span class="sub">Last '+(Number(d.range_days)||30)+' days</span></div>';
      html+='<div class="score-grid"><div class="gauge-wrap">'+gauge(score)+'</div><div class="kpis">'+kpi(num(d.total_visits||0),'Bot visits')+kpi(num(d.unique_pages||0),'Unique pages')+kpi(esc(grade(score)),'Rating')+'</div></div>';
      if(bars) html+='<div class="bars-title">Score breakdown</div><div class="bars">'+bars+'</div>';
      return html+'</div>';
    },
    get_bot_traffic: function(d){
      var pts=pointsByDay(d.recent_visits);
      var total=pts.reduce(function(a,p){return a+(Number(p.value)||0);},0);
      var rows=botTotals(d.total_by_bot).sort(function(a,b){return b.value-a.value;});
      var max=rows.length?Math.max(1,rows[0].value):1, top='';
      rows.slice(0,6).forEach(function(rw,i){ top+=bar(rw.label,Math.round(rw.value/max*100),num(rw.value),PAL[i%PAL.length]); });
      var html='<div class="card"><div class="head"><span class="kicker">AI Bot Traffic</span><span class="sub">Last '+(Number(d.days_range)||30)+' days</span></div>';
      html+='<div class="kpis row">'+kpi(num(total),'Visits in window')+kpi(num(pts.length),'Active days')+'</div>';
      html+='<div class="chart">'+lineChart(pts)+'</div>';
      if(top) html+='<div class="bars-title">Top crawlers (all time)</div><div class="bars">'+top+'</div>';
      return html+'</div>';
    },
    get_ai_visit_trends: function(d){
      var pts=pointsByDay(d.series);
      var total=pts.reduce(function(a,p){return a+(Number(p.value)||0);},0);
      var peak=pts.reduce(function(a,p){return Math.max(a,Number(p.value)||0);},0);
      var title=d.bot?(d.bot+' Visit Trends'):'AI Visit Trends';
      var html='<div class="card"><div class="head"><span class="kicker">'+esc(title)+'</span><span class="sub">Last '+(Number(d.days)||30)+' days</span></div>';
      html+='<div class="kpis row">'+kpi(num(total),'Total visits')+kpi(num(peak),'Peak day')+'</div>';
      html+='<div class="chart">'+lineChart(pts)+'</div>';
      return html+'</div>';
    },
    get_recommendations: function(d){
      var order={critical:0,high:1,medium:2,low:3}, pcls={critical:'p-critical',high:'p-high',medium:'p-medium',low:'p-low'};
      var recs=(d.recommendations||[]).slice().sort(function(a,b){ var pa=order[a.priority]==null?9:order[a.priority], pb=order[b.priority]==null?9:order[b.priority]; return pa-pb; });
      var fixUrl=d.admin_url||'';
      var fixBtn=fixUrl?'<div class="rec-fix"><button type="button" class="fix-btn" data-href="'+esc(fixUrl)+'">Fix</button></div>':'';
      var items='';
      recs.forEach(function(rw){ var p=rw.priority||'low'; items+='<li class="rec"><span class="badge '+(pcls[p]||'p-low')+'">'+esc(p.charAt(0).toUpperCase()+p.slice(1))+'</span><div class="rec-body">'+(rw.area?'<div class="rec-area">'+esc(String(rw.area).replace(/_/g,' '))+'</div>':'')+'<div class="rec-msg">'+esc(rw.message||'')+'</div>'+(rw.action?'<div class="rec-action">&rarr; '+esc(rw.action)+'</div>':'')+fixBtn+'</div></li>'; });
      if(!items) items='<li class="rec empty">No recommendations &mdash; your setup looks healthy.</li>';
      var score=Number(d.score)||0, sp=score<30?'p-critical':(score<60?'p-medium':'p-low');
      return '<div class="card"><div class="head"><span class="kicker">AI Visibility Recommendations</span><span class="pill '+sp+'">'+score+'/100</span></div><ul class="recs">'+items+'</ul></div>';
    }
  };

  function render(data){
    if(!data || typeof data!=='object') return;
    try{ var fn=R[TOOL]; root.innerHTML = fn ? fn(data) : '<div class="empty-chart">No renderer for '+esc(TOOL)+'.</div>'; rendered=true; }
    catch(e){ root.innerHTML='<div class="empty-chart">Could not render this view.</div>'; }
  }

  // Pull the data payload out of whatever shape the host delivers.
  function extract(p){
    if(!p) return null;
    if(p.structuredContent) return p.structuredContent;
    if(p.result&&p.result.structuredContent) return p.result.structuredContent;
    if(p.toolResult&&p.toolResult.structuredContent) return p.toolResult.structuredContent;
    if(p.toolOutput) return p.toolOutput;
    return p;
  }

  // ChatGPT (Apps SDK) path.
  function tryOpenAI(){
    try{ if(window.openai&&window.openai.toolOutput){ render(window.openai.toolOutput); return true; } }catch(e){}
    return false;
  }
  window.addEventListener('openai:set_globals', tryOpenAI);

  // MCP Apps standard bridge: JSON-RPC over postMessage.
  window.addEventListener('message', function(ev){
    var m=ev.data;
    if(!m||m.jsonrpc!=='2.0') return;
    if(m.method==='ui/notifications/tool-result'||m.method==='ui/notifications/tool-input'){ render(extract(m.params)); return; }
    if(m.result){ render(extract(m.result)); }
  }, {passive:true});

  function post(msg){ try{ if(window.parent&&window.parent!==window) window.parent.postMessage(msg,'*'); }catch(e){} }

  // Open an external link via the host (sandboxed iframes can't navigate directly).
  var linkReqId=0;
  function openLink(href){
    if(!href) return;
    try{ if(window.openai&&typeof window.openai.openExternal==='function'){ window.openai.openExternal({href:href}); return; } }catch(e){}
    if(window.parent&&window.parent!==window){ post({jsonrpc:'2.0',id:'open-link-'+(++linkReqId),method:'ui/open-link',params:{url:href}}); return; }
    try{ window.open(href,'_blank','noopener'); }catch(e){}
  }
  root.addEventListener('click', function(ev){
    var t=ev.target;
    while(t&&t!==root){ if(t.classList&&t.classList.contains('fix-btn')){ ev.preventDefault(); openLink(t.getAttribute('data-href')); return; } t=t.parentNode; }
  });

  // Announce readiness so the host pushes the tool result.
  post({jsonrpc:'2.0',id:'ui-init',method:'ui/initialize',params:{protocolVersion:'2025-06-18',capabilities:{}}});
  post({jsonrpc:'2.0',method:'ui/notifications/initialized',params:{}});

  root.innerHTML='<div class="empty-chart">Loading&hellip;</div>';
  tryOpenAI();
})();
JS;
	}
}
