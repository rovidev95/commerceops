(function () {
  'use strict';

  function parseJsonLd() {
    const scripts = document.querySelectorAll('script[type="application/ld+json"]');
    const graphs = [];
    scripts.forEach((node) => {
      try {
        const data = JSON.parse(node.textContent || '{}');
        if (data['@graph']) graphs.push(...data['@graph']);
        else graphs.push(data);
      } catch (e) { /* ignore invalid json */ }
    });
    return graphs;
  }

  function analyze() {
    const graphs = parseJsonLd();
    const products = graphs.filter((g) => g['@type'] === 'Product' || (Array.isArray(g['@type']) && g['@type'].includes('Product')));
    const issues = [];
    let score = 0;

    if (!products.length) {
      return { score: 0, issues: ['No Product schema detected.'] };
    }

    const p = products[0];
    if (p.name) score += 20; else issues.push('Missing product name');
    if (p.description) score += 15; else issues.push('Missing description');
    if (p.image) score += 15; else issues.push('Missing image');
    if (p.offers && p.offers.price) score += 20; else issues.push('Missing Offer price');
    if (p.aggregateRating) score += 20; else issues.push('Missing AggregateRating');
    if (p.gtin13 || p.gtin || p.sku) score += 10; else issues.push('Missing GTIN/SKU identifier');

    return { score: Math.min(100, score), issues };
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg.type === 'CO_GET_SCHEMA_REPORT') {
      sendResponse(analyze());
    }
    return true;
  });
})();
