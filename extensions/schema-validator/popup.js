function renderReport(report) {
  const scoreEl = document.getElementById('score');
  const issuesEl = document.getElementById('issues');
  if (!report) {
    scoreEl.textContent = 'N/A';
    issuesEl.innerHTML = '<li>No JSON-LD found on this tab.</li>';
    return;
  }
  scoreEl.textContent = report.score + '/100';
  issuesEl.innerHTML = report.issues.map((i) => `<li>${i}</li>`).join('');
}

chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
  const tabId = tabs[0]?.id;
  if (!tabId) return;
  chrome.tabs.sendMessage(tabId, { type: 'CO_GET_SCHEMA_REPORT' }, (resp) => {
    if (chrome.runtime.lastError) {
      chrome.scripting.executeScript({ target: { tabId }, files: ['content.js'] }, () => {
        chrome.tabs.sendMessage(tabId, { type: 'CO_GET_SCHEMA_REPORT' }, (resp2) => renderReport(resp2));
      });
      return;
    }
    renderReport(resp);
  });
});
