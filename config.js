async function exchange() {
  const amount = document.getElementById('amount').value;
  const fromCurrency = document.getElementById('fromCurrency').value;
  const toCurrency = document.getElementById('toCurrency').value;
  
  const response = await fetch(`http://localhost:5000/exchangeRate`);
  const data = await response.json();
  
  const rate = data[toCurrency];
  const result = amount * rate;
  
  document.getElementById('result').innerText = `Montan konvèti: ${result}`;
}
