import{r as c,j as o,_ as i,s as b}from"./index-9c2c3e00.js";import{B as Y,q as X}from"./mixpanel-f2075ead.js";import{R as Q}from"./data-skeleton-7ea2d889.js";import{i as K,u as Z}from"./use-bootstrap-d6127ccf.js";const s="llmagnet-llm-txt-generator";function ee({apiRoot:d,apiNamespace:g,isPremium:_=!1,planData:p}){const $=p?p.plan_name!=="free"||p.is_trial:_,y=(p==null?void 0:p.plan_name)==="enterprise",[k,A]=c.useState(""),[j,T]=c.useState("weekly"),[R,E]=c.useState("09:00"),[m,S]=c.useState(null),[N,C]=c.useState(!1),[z,v]=c.useState(null),[f,V]=c.useState(!1),[L,x]=c.useState(null),[P,M]=c.useState(""),[h,q]=c.useState("classic"),[O,W]=c.useState(!0),[u,w]=c.useState({product_crawled:!1,traffic_drop:!1,new_bot_detected:!1});c.useEffect(()=>{$&&(async()=>{var t;try{const n=await fetch(`${d}${g}/report-email`,{headers:{"X-WP-Nonce":((t=window.wpApiSettings)==null?void 0:t.nonce)||""}});if(n.ok){const e=await n.json();e.email&&A(e.email),e.template&&q(e.template),e.frequency&&T(e.frequency),e.send_time&&E(e.send_time),e.company_logo&&S(e.company_logo),e.event_alerts&&w({product_crawled:!!e.event_alerts.product_crawled,traffic_drop:!!e.event_alerts.traffic_drop,new_bot_detected:!!e.event_alerts.new_bot_detected})}}catch(n){console.error("Error fetching report settings:",n)}finally{W(!1)}})()},[$,d,g]);const B=(r={})=>{var t;return fetch(`${d}${g}/report-email`,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":((t=window.wpApiSettings)==null?void 0:t.nonce)||""},body:JSON.stringify({email:k,template:h,frequency:j,send_time:R,company_logo:m,event_alerts:u,...r})})},U=async()=>{if(!N){C(!0),v(null);try{const r=await B();if(!r.ok)throw new Error(`Error: ${r.status}`);const t=await r.json();t.success?(Y(j,h,!!m),X({has_email_reports:!!k}),v({type:"success",text:i("Report settings saved successfully.",s)})):v({type:"error",text:t.message||i("Failed to save settings.",s)})}catch(r){console.error("Error saving report settings:",r),v({type:"error",text:i("Failed to save settings. Please try again.",s)})}finally{C(!1),setTimeout(()=>{v(null)},3e3)}}},F=()=>{var t;if(!y)return;const r=(t=window.wp)==null?void 0:t.media({title:i("Select Company Logo",s),button:{text:i("Use this logo",s)},multiple:!1,library:{type:"image"}});r.on("select",()=>{const n=r.state().get("selection").first().toJSON();S({id:n.id,url:n.url})}),r.open()},I=()=>{S(null)},G=async r=>{q(r);try{await B({template:r})}catch(t){console.error("Error saving template:",t)}},H=async()=>{var r;if(!f){V(!0),x(null);try{const t=await fetch(`${d}${g}/send-report`,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":((r=window.wpApiSettings)==null?void 0:r.nonce)||""}});if(!t.ok)throw new Error(`Error: ${t.status}`);const n=await t.json();n.success?x({type:"success",text:i("Analytics report has been sent to your email.",s)}):x({type:"error",text:n.message||i("Failed to send analytics report.",s)})}catch(t){console.error("Error sending report:",t),x({type:"error",text:i("Failed to send analytics report. Please try again later.",s)})}finally{V(!1),setTimeout(()=>{x(null)},5e3)}}};if(!$)return o.jsxs("div",{className:"wrap",children:[o.jsx("h1",{className:"text-2xl font-semibold text-gray-900 mb-4",children:i("Analytics Reports",s)}),o.jsx("div",{className:"bg-white border border-gray-200 shadow-sm p-6 rounded-md",children:o.jsxs("p",{className:"text-gray-600",children:[i("Analytics Reports is a premium feature. Please",s)," ",o.jsx("a",{href:"/wp-admin/admin.php?page=llmagnet-ai-seo-optimizer-pricing",style:{color:"#7C3AED",fontWeight:600},children:i("upgrade",s)})," ",i("to access this functionality.",s)]})})]});const D={site_name:i("My Website",s),week_range:"Jan 15 - Jan 21, 2024",company_logo:(m==null?void 0:m.url)||null,weekly_stats:[{bot_name:"ChatGPT",visits:1250},{bot_name:"Claude",visits:890},{bot_name:"Perplexity",visits:645},{bot_name:"Gemini",visits:432},{bot_name:"Grok",visits:321}],all_time_stats:[{bot_name:"ChatGPT",visits:15230},{bot_name:"Claude",visits:10890},{bot_name:"Perplexity",visits:7645},{bot_name:"Gemini",visits:5432},{bot_name:"Grok",visits:3321}],visibility_score:{score:72,total_visits:3538,unique_pages:24,breakdown:{frequency:{score:85,weight:25,label:i("Crawl Frequency",s)},visit_type:{score:68,weight:20,label:i("Visit Types",s)},visit_count:{score:75,weight:25,label:i("Visit Volume",s)},page_status:{score:90,weight:15,label:i("Page Health",s)},url_type:{score:55,weight:15,label:i("URL Structure",s)}}}},a=r=>r>=75?"#22c55e":r>=50?"#eab308":"#ef4444",J=r=>{const t={companyLogoAlt:i("Company Logo",s),logoAlt:i("Logo",s),llmBotAnalyticsReport:i("LLM Bot Analytics Report",s),aiVisibilityScore:i("AI Visibility Score",s),frequency:i("Frequency",s),visitTypes:i("Visit Types",s),volume:i("Volume",s),health:i("Health",s),url:i("URL",s),weeklySummary:i("Weekly Summary",s),botName:i("Bot Name",s),visits:i("Visits",s),allTimeBotVisits:i("All-Time Bot Visits",s),totalVisits:i("Total Visits",s),footerAutomated:i("This is an automated report generated by LLMagnet AI SEO Optimizer.",s),llmBotAnalytics:i("LLM Bot Analytics",s),weeklyVisits:i("Weekly Visits",s),bot:i("Bot",s),allTimeTotal:i("All-Time Total",s),total:i("Total",s),aiVisibilityReport:i("AI Visibility Report",s),visibilityScore:i("Visibility Score",s),weeklyBotVisits:i("Weekly Bot Visits",s),allTimeStatistics:i("All-Time Statistics",s),poweredBy:i("Powered by",s),dearAdministrator:i("Dear Administrator,",s),introLetter:i("Please find below the comprehensive analytics report for LLM bot visits to your website. This report covers your AI Visibility Score, weekly performance metrics, and cumulative all-time statistics.",s),weeklyPerformance:i("Weekly Performance Metrics",s),cumulativeStats:i("Cumulative Statistics",s),closingLetter:i("Should you require any additional information or have questions regarding this report, please do not hesitate to contact us.",s),respectfully:i("Respectfully,",s),automatedReporting:i("Automated Reporting System",s),weekOf:i("Week of",s),productName:i("LLMagnet AI SEO Optimizer",s),aiSeoOptimizer:i("AI SEO Optimizer",s),freq:i("Freq",s),types:i("Types",s),vol:i("Vol",s),visitsAcrossPages:(e,l)=>b(i("%1$s visits across %2$d pages",s),e,l),visitsPages:(e,l)=>b(i("%1$s visits • %2$d pages",s),e,l),weeklySummaryBody:e=>b(i("This report shows LLM bot visits to your website during the week of %s.",s),e),botVisitsWeek:e=>b(i("Bot Visits for the Week of %s",s),e),weekOfRange:e=>b(i("Week of %s",s),e),totalVisitsAcrossPages:(e,l)=>b(i("%1$s total visits across %2$d pages",s),e,l)},n={classic:e=>`
        <!DOCTYPE html>
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
              * { margin: 0; padding: 0; box-sizing: border-box; }
              body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                background-color: #ffffff;
              }
              .header {
                background-color: #5d4066;
                color: white;
                padding: 20px;
                border-radius: 5px 5px 0 0;
                margin-bottom: 20px;
              }
              .header h1 { margin: 0 0 10px 0; font-size: 24px; }
              .header p { margin: 0; font-size: 14px; opacity: 0.9; }
              .summary {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
              }
              .summary h2 { margin: 0 0 10px 0; font-size: 18px; color: #333; }
              .summary p { margin: 0; font-size: 14px; color: #666; }
              .visibility-score-box {
                background-color: #ffffff;
                border-radius: 10px;
                padding: 20px;
                margin: 20px 0;
                color: #000000;
                text-align: center;
              }
              .visibility-score-box h2 { margin: 0 0 15px 0; font-size: 18px; font-weight: 500; }
              .score-circle {
                display: inline-block;
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: rgba(255,255,255,0.2);
                line-height: 80px;
                font-size: 32px;
                font-weight: bold;
                margin-bottom: 10px;
              }
              .score-details {
                display: flex;
                justify-content: space-around;
                margin-top: 15px;
                flex-wrap: wrap;
              }
              .score-item {
                text-align: center;
                padding: 5px 10px;
              }
              .score-item-label { font-size: 11px; opacity: 0.9; }
              .score-item-value { font-size: 16px; font-weight: bold; }
              .score-green { color: #22c55e; }
              .score-yellow { color: #eab308; }
              .score-red { color: #ef4444; }
              table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                background-color: white;
              }
              th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
              th { background-color: #f5f5f5; font-weight: bold; }
              .bot-name { font-weight: bold; }
              .visits { font-weight: bold; color: #a070b0; }
              h3 { margin: 20px 0 10px 0; font-size: 16px; color: #333; }
              .footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                font-size: 12px;
                color: #666;
              }
            </style>
          </head>
          <body>
            <div class="header">
              ${e.company_logo?`
                <div style="margin-bottom: 15px;">
                  <img src="${e.company_logo}" alt="${t.companyLogoAlt}" style="max-height: 50px; max-width: 150px; object-fit: contain;" />
                </div>
              `:""}
              <h1>${t.llmBotAnalyticsReport}</h1>
              <p>${e.site_name} - ${t.weekOfRange(e.week_range)}</p>
            </div>
            <div class="visibility-score-box">
              <h2>${t.aiVisibilityScore}</h2>
              <div class="score-circle" style="background-color: ${a(e.visibility_score.score)};">${e.visibility_score.score}</div>
              <div style="font-size: 14px; opacity: 0.9;">${t.visitsAcrossPages(e.visibility_score.total_visits.toLocaleString(),e.visibility_score.unique_pages)}</div>
              <div class="score-details">
                <div class="score-item">
                  <div class="score-item-value" style="color: ${a(e.visibility_score.breakdown.frequency.score)};">${e.visibility_score.breakdown.frequency.score}</div>
                  <div class="score-item-label">${t.frequency}</div>
                </div>
                <div class="score-item">
                  <div class="score-item-value" style="color: ${a(e.visibility_score.breakdown.visit_type.score)};">${e.visibility_score.breakdown.visit_type.score}</div>
                  <div class="score-item-label">${t.visitTypes}</div>
                </div>
                <div class="score-item">
                  <div class="score-item-value" style="color: ${a(e.visibility_score.breakdown.visit_count.score)};">${e.visibility_score.breakdown.visit_count.score}</div>
                  <div class="score-item-label">${t.volume}</div>
                </div>
                <div class="score-item">
                  <div class="score-item-value" style="color: ${a(e.visibility_score.breakdown.page_status.score)};">${e.visibility_score.breakdown.page_status.score}</div>
                  <div class="score-item-label">${t.health}</div>
                </div>
                <div class="score-item">
                  <div class="score-item-value" style="color: ${a(e.visibility_score.breakdown.url_type.score)};">${e.visibility_score.breakdown.url_type.score}</div>
                  <div class="score-item-label">${t.url}</div>
                </div>
              </div>
            </div>
            <div class="summary">
              <h2>${t.weeklySummary}</h2>
              <p>${t.weeklySummaryBody(e.week_range)}</p>
            </div>
            <h3>${t.botVisitsWeek(e.week_range)}</h3>
            <table>
              <tr><th>${t.botName}</th><th>${t.visits}</th></tr>
              ${e.weekly_stats.map(l=>`
                <tr>
                  <td class="bot-name">${l.bot_name}</td>
                  <td class="visits">${l.visits.toLocaleString()}</td>
                </tr>
              `).join("")}
            </table>
            <h3>${t.allTimeBotVisits}</h3>
            <table>
              <tr><th>${t.botName}</th><th>${t.totalVisits}</th></tr>
              ${e.all_time_stats.map(l=>`
                <tr>
                  <td class="bot-name">${l.bot_name}</td>
                  <td class="visits">${l.visits.toLocaleString()}</td>
                </tr>
              `).join("")}
            </table>
            <div class="footer">
              <p>${t.footerAutomated}</p>
            </div>
          </body>
        </html>
      `,minimal:e=>`
        <!DOCTYPE html>
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
              * { margin: 0; padding: 0; box-sizing: border-box; }
              body {
                font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
                line-height: 1.8;
                color: #2c3e50;
                max-width: 600px;
                margin: 0 auto;
                padding: 40px 20px;
                background-color: #f8f9fa;
              }
              .header {
                text-align: center;
                padding-bottom: 30px;
                border-bottom: 3px solid #2c3e50;
                margin-bottom: 30px;
              }
              .header h1 {
                font-size: 32px;
                font-weight: 300;
                color: #2c3e50;
                margin-bottom: 10px;
                letter-spacing: 2px;
              }
              .header p {
                font-size: 14px;
                color: #7f8c8d;
                text-transform: uppercase;
                letter-spacing: 1px;
              }
              .visibility-score {
                text-align: center;
                padding: 30px;
                margin: 30px 0;
                border: 2px solid #2c3e50;
              }
              .visibility-score h2 {
                font-size: 14px;
                font-weight: 400;
                text-transform: uppercase;
                letter-spacing: 2px;
                color: #7f8c8d;
                margin-bottom: 15px;
              }
              .visibility-score .score-value {
                font-size: 64px;
                font-weight: 200;
                color: #2c3e50;
                line-height: 1;
              }
              .visibility-score .score-meta {
                font-size: 12px;
                color: #95a5a6;
                margin-top: 10px;
              }
              .score-breakdown {
                display: flex;
                justify-content: space-between;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #ecf0f1;
              }
              .score-breakdown-item {
                text-align: center;
              }
              .score-breakdown-item .value {
                font-size: 20px;
                font-weight: 300;
                color: #2c3e50;
              }
              .score-breakdown-item .label {
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #95a5a6;
              }
              .section {
                margin: 40px 0;
              }
              .section-title {
                font-size: 18px;
                font-weight: 400;
                color: #34495e;
                margin-bottom: 20px;
                text-transform: uppercase;
                letter-spacing: 1px;
                border-left: 4px solid #2c3e50;
                padding-left: 15px;
              }
              table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
              }
              th {
                text-align: left;
                padding: 15px 0;
                border-bottom: 2px solid #2c3e50;
                font-weight: 400;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #7f8c8d;
              }
              td {
                padding: 15px 0;
                border-bottom: 1px solid #ecf0f1;
              }
              .bot-name {
                font-weight: 500;
                color: #2c3e50;
              }
              .visits {
                color: #34495e;
                font-size: 18px;
              }
              .footer {
                margin-top: 50px;
                padding-top: 30px;
                border-top: 1px solid #ecf0f1;
                text-align: center;
                font-size: 11px;
                color: #95a5a6;
                text-transform: uppercase;
                letter-spacing: 1px;
              }
            </style>
          </head>
          <body>
            <div class="header">
              ${e.company_logo?`
                <div style="margin-bottom: 20px; text-align: center;">
                  <img src="${e.company_logo}" alt="${t.companyLogoAlt}" style="max-height: 40px; max-width: 120px; object-fit: contain;" />
                </div>
              `:""}
              <h1>${t.llmBotAnalytics}</h1>
              <p>${e.site_name} • ${e.week_range}</p>
            </div>
            <div class="visibility-score">
              <h2>${t.aiVisibilityScore}</h2>
              <div class="score-value" style="color: ${a(e.visibility_score.score)};">${e.visibility_score.score}</div>
              <div class="score-meta">${t.visitsPages(e.visibility_score.total_visits.toLocaleString(),e.visibility_score.unique_pages)}</div>
              <div class="score-breakdown">
                <div class="score-breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.frequency.score)};">${e.visibility_score.breakdown.frequency.score}</div>
                  <div class="label">${t.freq}</div>
                </div>
                <div class="score-breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.visit_type.score)};">${e.visibility_score.breakdown.visit_type.score}</div>
                  <div class="label">${t.types}</div>
                </div>
                <div class="score-breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.visit_count.score)};">${e.visibility_score.breakdown.visit_count.score}</div>
                  <div class="label">${t.vol}</div>
                </div>
                <div class="score-breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.page_status.score)};">${e.visibility_score.breakdown.page_status.score}</div>
                  <div class="label">${t.health}</div>
                </div>
                <div class="score-breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.url_type.score)};">${e.visibility_score.breakdown.url_type.score}</div>
                  <div class="label">${t.url}</div>
                </div>
              </div>
            </div>
            <div class="section">
              <div class="section-title">${t.weeklyVisits}</div>
              <table>
                <tr>
                  <th>${t.bot}</th>
                  <th style="text-align: right;">${t.visits}</th>
                </tr>
                ${e.weekly_stats.map(l=>`
                  <tr>
                    <td class="bot-name">${l.bot_name}</td>
                    <td class="visits" style="text-align: right;">${l.visits.toLocaleString()}</td>
                  </tr>
                `).join("")}
              </table>
            </div>
            <div class="section">
              <div class="section-title">${t.allTimeTotal}</div>
              <table>
                <tr>
                  <th>${t.bot}</th>
                  <th style="text-align: right;">${t.total}</th>
                </tr>
                ${e.all_time_stats.map(l=>`
                  <tr>
                    <td class="bot-name">${l.bot_name}</td>
                    <td class="visits" style="text-align: right;">${l.visits.toLocaleString()}</td>
                  </tr>
                `).join("")}
              </table>
            </div>
            <div class="footer">
              ${t.productName}
            </div>
          </body>
        </html>
      `,gradient:e=>`
        <!DOCTYPE html>
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
              * { margin: 0; padding: 0; box-sizing: border-box; }
              body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                line-height: 1.6;
                color: #1f2937;
                max-width: 600px;
                margin: 0 auto;
                background-color: #f3f4f6;
              }
              .email-wrap { background: #ffffff; border-radius: 12px; overflow: hidden; margin: 20px; }
              .header {
                background: linear-gradient(135deg, #301630 0%, #512756 20%, #000000 40%, #2B1434 60%, #E276F0 80%, #E7AFE7 100%);
                padding: 32px 28px;
                color: #ffffff;
                text-align: center;
              }
              .header img { max-height: 44px; max-width: 140px; margin-bottom: 16px; object-fit: contain; }
              .header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
              .header p  { font-size: 13px; opacity: 0.85; }
              .score-card {
                margin: -24px 24px 0;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                padding: 24px;
                text-align: center;
                position: relative;
                z-index: 1;
              }
              .score-card h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 1.5px; color: #9ca3af; margin-bottom: 12px; }
              .score-number { font-size: 52px; font-weight: 800; line-height: 1; }
              .score-meta { font-size: 12px; color: #6b7280; margin-top: 8px; }
              .score-grid { display: flex; justify-content: space-between; margin-top: 18px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
              .score-grid-item { text-align: center; flex: 1; }
              .score-grid-item .val { font-size: 18px; font-weight: 700; }
              .score-grid-item .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-top: 2px; }
              .section { padding: 28px 24px; }
              .section-title {
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #a855f7;
                margin-bottom: 16px;
                display: flex;
                align-items: center;
                gap: 8px;
              }
              .section-title::after { content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, #e9d5ff, transparent); }
              table { width: 100%; border-collapse: collapse; }
              th {
                text-align: left;
                padding: 10px 12px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #9ca3af;
                border-bottom: 2px solid #f3f4f6;
              }
              td { padding: 12px; border-bottom: 1px solid #f9fafb; font-size: 14px; }
              .bot-name { font-weight: 600; color: #1f2937; }
              .visits { font-weight: 700; color: #7c3aed; text-align: right; }
              th:last-child { text-align: right; }
              .footer {
                background: #f9fafb;
                padding: 20px 24px;
                text-align: center;
                font-size: 11px;
                color: #9ca3af;
              }
              .footer a { color: #a855f7; text-decoration: none; }
            </style>
          </head>
          <body>
            <div class="email-wrap">
              <div class="header">
                ${e.company_logo?`<img src="${e.company_logo}" alt="${t.logoAlt}" />`:""}
                <h1>${t.aiVisibilityReport}</h1>
                <p>${e.site_name} &mdash; ${e.week_range}</p>
              </div>

              <div class="score-card">
                <h2>${t.visibilityScore}</h2>
                <div class="score-number" style="color: ${a(e.visibility_score.score)};">
                  ${e.visibility_score.score}
                </div>
                <div class="score-meta">
                  ${t.visitsAcrossPages(e.visibility_score.total_visits.toLocaleString(),e.visibility_score.unique_pages)}
                </div>
                <div class="score-grid">
                  <div class="score-grid-item">
                    <div class="val" style="color:${a(e.visibility_score.breakdown.frequency.score)}">${e.visibility_score.breakdown.frequency.score}</div>
                    <div class="lbl">${t.freq}</div>
                  </div>
                  <div class="score-grid-item">
                    <div class="val" style="color:${a(e.visibility_score.breakdown.visit_type.score)}">${e.visibility_score.breakdown.visit_type.score}</div>
                    <div class="lbl">${t.types}</div>
                  </div>
                  <div class="score-grid-item">
                    <div class="val" style="color:${a(e.visibility_score.breakdown.visit_count.score)}">${e.visibility_score.breakdown.visit_count.score}</div>
                    <div class="lbl">${t.volume}</div>
                  </div>
                  <div class="score-grid-item">
                    <div class="val" style="color:${a(e.visibility_score.breakdown.page_status.score)}">${e.visibility_score.breakdown.page_status.score}</div>
                    <div class="lbl">${t.health}</div>
                  </div>
                  <div class="score-grid-item">
                    <div class="val" style="color:${a(e.visibility_score.breakdown.url_type.score)}">${e.visibility_score.breakdown.url_type.score}</div>
                    <div class="lbl">${t.url}</div>
                  </div>
                </div>
              </div>

              <div class="section">
                <div class="section-title">${t.weeklyBotVisits}</div>
                <table>
                  <tr><th>${t.bot}</th><th>${t.visits}</th></tr>
                  ${e.weekly_stats.map(l=>`
                    <tr>
                      <td class="bot-name">${l.bot_name}</td>
                      <td class="visits">${l.visits.toLocaleString()}</td>
                    </tr>
                  `).join("")}
                </table>
              </div>

              <div class="section" style="padding-top: 0;">
                <div class="section-title">${t.allTimeStatistics}</div>
                <table>
                  <tr><th>${t.bot}</th><th>${t.total}</th></tr>
                  ${e.all_time_stats.map(l=>`
                    <tr>
                      <td class="bot-name">${l.bot_name}</td>
                      <td class="visits">${l.visits.toLocaleString()}</td>
                    </tr>
                  `).join("")}
                </table>
              </div>

              <div class="footer">
                ${t.poweredBy} <a href="https://llmagnet.com">LLMagnet</a> ${t.aiSeoOptimizer}
              </div>
            </div>
          </body>
        </html>
      `,professional:e=>`
        <!DOCTYPE html>
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
              * { margin: 0; padding: 0; box-sizing: border-box; }
              body {
                font-family: Georgia, "Times New Roman", serif;
                line-height: 1.7;
                color: #1a1a1a;
                max-width: 650px;
                margin: 0 auto;
                padding: 30px;
                background-color: #ffffff;
                border: 1px solid #e0e0e0;
              }
              .letterhead {
                border-bottom: 3px solid #1a1a1a;
                padding-bottom: 20px;
                margin-bottom: 30px;
              }
              .letterhead h1 {
                font-size: 36px;
                font-weight: 400;
                color: #1a1a1a;
                margin-bottom: 5px;
                letter-spacing: 1px;
              }
              .letterhead .subtitle {
                font-size: 14px;
                color: #666;
                font-style: italic;
              }
              .date {
                text-align: right;
                margin-bottom: 30px;
                color: #666;
                font-size: 14px;
              }
              .intro {
                margin-bottom: 30px;
                font-size: 15px;
                line-height: 1.8;
                color: #333;
              }
              .visibility-section {
                background-color: #f8f8f8;
                border: 2px solid #1a1a1a;
                padding: 25px;
                margin: 30px 0;
              }
              .visibility-section h2 {
                font-size: 18px;
                font-weight: 400;
                color: #1a1a1a;
                margin-bottom: 20px;
                text-align: center;
              }
              .visibility-main {
                text-align: center;
                margin-bottom: 20px;
              }
              .visibility-main .score {
                font-size: 48px;
                font-weight: 600;
                color: #1a1a1a;
              }
              .visibility-main .score-label {
                font-size: 12px;
                color: #666;
                font-style: italic;
              }
              .visibility-breakdown {
                display: flex;
                justify-content: space-between;
                border-top: 1px solid #e0e0e0;
                padding-top: 15px;
              }
              .breakdown-item {
                text-align: center;
                flex: 1;
              }
              .breakdown-item .value {
                font-size: 18px;
                font-weight: 600;
                color: #1a1a1a;
              }
              .breakdown-item .label {
                font-size: 11px;
                color: #666;
              }
              .report-section {
                margin: 35px 0;
              }
              .report-section h2 {
                font-size: 22px;
                font-weight: 400;
                color: #1a1a1a;
                margin-bottom: 20px;
                border-bottom: 2px solid #1a1a1a;
                padding-bottom: 8px;
              }
              table {
                width: 100%;
                border-collapse: collapse;
                margin: 25px 0;
                font-size: 14px;
              }
              th {
                background-color: #1a1a1a;
                color: white;
                padding: 12px 15px;
                text-align: left;
                font-weight: 400;
                font-size: 13px;
                letter-spacing: 0.5px;
              }
              td {
                padding: 12px 15px;
                border-bottom: 1px solid #e0e0e0;
              }
              tr:last-child td { border-bottom: 2px solid #1a1a1a; }
              .bot-name {
                font-weight: 500;
                color: #1a1a1a;
              }
              .visits {
                font-weight: 600;
                color: #1a1a1a;
              }
              .closing {
                margin-top: 40px;
                font-size: 14px;
                line-height: 1.8;
                color: #333;
              }
              .signature {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e0e0e0;
                font-size: 12px;
                color: #666;
                font-style: italic;
              }
            </style>
          </head>
          <body>
            <div class="letterhead">
              ${e.company_logo?`
                <div style="margin-bottom: 20px;">
                  <img src="${e.company_logo}" alt="${t.companyLogoAlt}" style="max-height: 60px; max-width: 180px; object-fit: contain;" />
                </div>
              `:""}
              <h1>${t.llmBotAnalyticsReport}</h1>
              <div class="subtitle">${e.site_name}</div>
            </div>
            <div class="date">${t.weekOfRange(e.week_range)}</div>
            <div class="intro">
              ${t.dearAdministrator}<br><br>
              ${t.introLetter}
            </div>
            <div class="visibility-section">
              <h2>${t.aiVisibilityScore}</h2>
              <div class="visibility-main">
                <div class="score" style="color: ${a(e.visibility_score.score)};">${e.visibility_score.score}</div>
                <div class="score-label">${t.totalVisitsAcrossPages(e.visibility_score.total_visits.toLocaleString(),e.visibility_score.unique_pages)}</div>
              </div>
              <div class="visibility-breakdown">
                <div class="breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.frequency.score)};">${e.visibility_score.breakdown.frequency.score}</div>
                  <div class="label">${t.frequency}</div>
                </div>
                <div class="breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.visit_type.score)};">${e.visibility_score.breakdown.visit_type.score}</div>
                  <div class="label">${t.visitTypes}</div>
                </div>
                <div class="breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.visit_count.score)};">${e.visibility_score.breakdown.visit_count.score}</div>
                  <div class="label">${t.volume}</div>
                </div>
                <div class="breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.page_status.score)};">${e.visibility_score.breakdown.page_status.score}</div>
                  <div class="label">${t.health}</div>
                </div>
                <div class="breakdown-item">
                  <div class="value" style="color: ${a(e.visibility_score.breakdown.url_type.score)};">${e.visibility_score.breakdown.url_type.score}</div>
                  <div class="label">${t.url}</div>
                </div>
              </div>
            </div>
            <div class="report-section">
              <h2>${t.weeklyPerformance}</h2>
              <table>
                <tr>
                  <th>${t.botName}</th>
                  <th style="text-align: right;">${t.visits}</th>
                </tr>
                ${e.weekly_stats.map(l=>`
                  <tr>
                    <td class="bot-name">${l.bot_name}</td>
                    <td class="visits" style="text-align: right;">${l.visits.toLocaleString()}</td>
                  </tr>
                `).join("")}
              </table>
            </div>
            <div class="report-section">
              <h2>${t.cumulativeStats}</h2>
              <table>
                <tr>
                  <th>${t.botName}</th>
                  <th style="text-align: right;">${t.totalVisits}</th>
                </tr>
                ${e.all_time_stats.map(l=>`
                  <tr>
                    <td class="bot-name">${l.bot_name}</td>
                    <td class="visits" style="text-align: right;">${l.visits.toLocaleString()}</td>
                  </tr>
                `).join("")}
              </table>
            </div>
            <div class="closing">
              ${t.closingLetter}
            </div>
            <div class="signature">
              ${t.respectfully}<br>
              ${t.productName}<br>
              ${t.automatedReporting}
            </div>
          </body>
        </html>
      `};return n[r]||n.classic};return c.useEffect(()=>{const t=J(h)(D),n=new Blob([t],{type:"text/html"}),e=URL.createObjectURL(n);return M(e),()=>{URL.revokeObjectURL(e)}},[h,m,y]),o.jsx("div",{className:"wrap",style:{maxWidth:"1200px",margin:"auto",paddingTop:"1.5rem",paddingLeft:"1.5rem",paddingRight:"1.5rem"},children:o.jsxs("div",{className:"grid grid-cols-1 lg:grid-cols-2 gap-6",children:[o.jsxs("div",{className:"bg-white p-6",style:{boxShadow:"0px 5.4px 10.79px 0px rgba(0, 0, 0, 0.03)"},children:[o.jsx("h2",{className:"text-xl font-semibold text-gray-900 mb-6",children:i("Email Report Settings",s)}),O?o.jsx(Q,{}):o.jsxs("div",{className:"flex flex-col gap-6",children:[o.jsxs("div",{children:[o.jsx("label",{htmlFor:"report-email",className:"block text-base font-medium text-gray-800 mb-2",children:i("Report Recipients",s)}),o.jsx("p",{className:"text-sm text-gray-500 mb-3",children:i("Add multiple email addresses separated by commas (e.g., admin@example.com, team@example.com)",s)}),o.jsx("input",{id:"report-email",type:"text",value:k,onChange:r=>A(r.target.value),className:"w-full rounded border-solid bg-white px-3 py-2 text-sm !border !border-gray-300 focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500",placeholder:"admin@example.com, team@example.com"})]}),o.jsxs("div",{className:"grid grid-cols-2 gap-4",children:[o.jsxs("div",{children:[o.jsx("label",{htmlFor:"report-frequency",className:"block text-base font-medium text-gray-800 mb-2",children:i("Report Frequency",s)}),o.jsxs("select",{id:"report-frequency",value:j,onChange:r=>T(r.target.value),className:"w-full rounded border-solid bg-white px-3 py-2 text-sm !border !border-gray-300 focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500",children:[o.jsx("option",{value:"daily",children:i("Daily",s)}),o.jsx("option",{value:"weekly",children:i("Weekly",s)}),o.jsx("option",{value:"monthly",children:i("Monthly",s)}),o.jsx("option",{value:"quarterly",children:i("Quarterly",s)})]})]}),o.jsxs("div",{children:[o.jsx("label",{htmlFor:"report-time",className:"block text-base font-medium text-gray-800 mb-2",children:i("Send Time",s)}),o.jsx("input",{id:"report-time",type:"time",value:R,onChange:r=>E(r.target.value),className:"w-full rounded border-solid bg-white px-3 py-2 text-sm !border !border-gray-300 focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500"})]})]}),o.jsxs("div",{children:[o.jsxs("div",{className:"flex items-center gap-2 mb-2",children:[o.jsx("label",{className:"block text-base font-medium text-gray-800",children:i("Company Logo",s)}),!y&&o.jsxs("a",{href:"/wp-admin/admin.php?page=llmagnet-ai-seo-optimizer-pricing",className:"inline-flex items-center gap-1 text-xs py-0.5 px-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-full hover:opacity-90 hover:text-white transition-opacity no-underline",children:[o.jsx("svg",{className:"w-3 h-3",fill:"none",viewBox:"0 0 24 24",stroke:"currentColor",children:o.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"})}),i("Enterprise",s)]})]}),o.jsx("p",{className:"text-sm text-gray-500 mb-3",children:i("Add your company logo to email reports. Available for Enterprise users only.",s)}),y?o.jsx("div",{className:"flex items-center gap-4",children:m?o.jsxs("div",{className:"flex items-center gap-3",children:[o.jsx("div",{className:"w-16 h-16 border border-gray-200 rounded-lg overflow-hidden bg-gray-50 flex items-center justify-center",children:o.jsx("img",{src:m.url,alt:i("Company Logo",s),className:"max-w-full max-h-full object-contain"})}),o.jsxs("div",{className:"flex flex-col gap-2",children:[o.jsx("button",{onClick:F,className:"text-sm text-purple-600 hover:text-purple-700 font-medium",children:i("Change Logo",s)}),o.jsx("button",{onClick:I,className:"text-sm text-red-500 hover:text-red-600",children:i("Remove",s)})]})]}):o.jsxs("button",{onClick:F,className:"inline-flex items-center gap-2 px-4 py-2 border-2 border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-purple-400 hover:text-purple-600 transition-colors",children:[o.jsx("svg",{className:"w-5 h-5",fill:"none",viewBox:"0 0 24 24",stroke:"currentColor",children:o.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"})}),i("Select Logo",s)]})}):o.jsxs("div",{className:"flex items-center gap-2 px-4 py-3 bg-gray-50 rounded-lg border border-gray-200",children:[o.jsx("svg",{className:"w-5 h-5 text-gray-400",fill:"none",viewBox:"0 0 24 24",stroke:"currentColor",children:o.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"})}),o.jsx("span",{className:"text-sm text-gray-500",children:i("Upgrade to Enterprise to add your company logo",s)})]})]}),o.jsxs("div",{className:"border-t border-gray-100 pt-6",children:[o.jsx("h3",{className:"text-base font-medium text-gray-800 mb-2",children:i("Event alerts (opt-in)",s)}),o.jsx("p",{className:"text-sm text-gray-500 mb-4",children:i("Immediate emails when notable AI traffic events occur. Uses the report recipients above.",s)}),o.jsxs("div",{className:"space-y-3",children:[o.jsxs("label",{className:"flex items-start gap-3 text-sm text-gray-700",children:[o.jsx("input",{type:"checkbox",className:"mt-1",checked:u.product_crawled,onChange:r=>w(t=>({...t,product_crawled:r.target.checked}))}),o.jsx("span",{children:i("New product crawled within an hour of publish",s)})]}),o.jsxs("label",{className:"flex items-start gap-3 text-sm text-gray-700",children:[o.jsx("input",{type:"checkbox",className:"mt-1",checked:u.traffic_drop,onChange:r=>w(t=>({...t,traffic_drop:r.target.checked}))}),o.jsx("span",{children:i("Bot traffic drops more than 40% week-over-week",s)})]}),o.jsxs("label",{className:"flex items-start gap-3 text-sm text-gray-700",children:[o.jsx("input",{type:"checkbox",className:"mt-1",checked:u.new_bot_detected,onChange:r=>w(t=>({...t,new_bot_detected:r.target.checked}))}),o.jsx("span",{children:i("A new AI bot type is detected on your site",s)})]})]})]}),o.jsxs("div",{className:"pt-4",children:[o.jsx("button",{onClick:U,disabled:N,className:"inline-flex items-center px-6 py-2.5 text-sm font-medium rounded-lg text-white bg-gray-900 hover:bg-gray-800 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed transition-colors",children:N?i("Saving...",s):i("Save Settings",s)}),z&&o.jsx("p",{className:`mt-2 text-sm ${z.type==="success"?"text-green-600":"text-red-600"}`,children:z.text})]}),o.jsxs("div",{className:"border-t border-gray-100 pt-6",children:[o.jsx("h3",{className:"text-base font-medium text-gray-800 mb-2",children:i("Send Report Now",s)}),o.jsx("p",{className:"text-sm text-gray-500 mb-4",children:i("Send an analytics report immediately to the recipients above.",s)}),o.jsxs("div",{className:"flex flex-col items-start",children:[o.jsx("button",{onClick:H,disabled:f,className:`inline-block px-6 py-3 bg-gray-900 text-white rounded-full font-medium hover:bg-gray-800 transition-colors ${f?"opacity-70 cursor-not-allowed":""}`,children:f?i("Sending...",s):i("Send Analytics Report Now",s)}),L&&o.jsx("div",{className:`mt-3 p-3 rounded text-sm ${L.type==="success"?"bg-green-50 text-green-700":"bg-red-50 text-red-700"}`,children:L.text})]})]})]})]}),o.jsxs("div",{className:"bg-white p-6",style:{boxShadow:"0px 5.4px 10.79px 0px rgba(0, 0, 0, 0.03)"},children:[o.jsxs("div",{className:"flex justify-between items-center mb-6",children:[o.jsx("h2",{className:"text-xl font-semibold text-gray-900",children:i("Email Template",s)}),o.jsxs("div",{className:"flex items-center gap-2",children:[o.jsx("label",{htmlFor:"template-select",className:"text-sm text-gray-600",children:i("Template:",s)}),o.jsxs("select",{id:"template-select",value:h,onChange:r=>G(r.target.value),className:"rounded border-solid bg-white px-3 py-1.5 text-sm !border !border-gray-300 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500",children:[o.jsx("option",{value:"classic",children:i("Classic",s)}),o.jsx("option",{value:"minimal",children:i("Minimal",s)}),o.jsx("option",{value:"gradient",children:i("Gradient",s)}),o.jsx("option",{value:"professional",children:i("Professional",s)})]})]})]}),o.jsx("div",{className:"rounded overflow-hidden bg-white border border-gray-200",style:{height:"600px"},children:P?o.jsx("iframe",{src:P,className:"w-full h-full border-0",title:i("Email Preview",s),sandbox:"allow-same-origin",style:{minHeight:"600px"}}):o.jsx("div",{className:"flex items-center justify-center h-full",children:o.jsx("p",{className:"text-gray-500",children:i("Loading email preview...",s)})})})]})]})})}K("llmagnetReportsData","Reports");function re(){var p;const{data:d}=Z("llmagnetReportsData");if(!d)return o.jsx("div",{className:"p-4 text-red-600",children:i("Reports data not found.","llmagnet-llm-txt-generator")});const g=((p=window.wpApiSettings)==null?void 0:p.root)||"",_="llm-analytics/v1";return o.jsx("div",{className:"llms-reports-react-app",style:{position:"relative",backgroundColor:"#F0F0F1"},children:o.jsx(ee,{apiRoot:g,apiNamespace:_,isPremium:d.planData.plan_name!=="free"||d.planData.is_trial,planData:d.planData})})}export{re as ReportsApp};
