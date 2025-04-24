async function exchange() {
    const amount = document.getElementById('amount').value;
    const fromCurrency = document.getElementById('fromCurrency').value;
    const toCurrency = document.getElementById('toCurrency').value;
    
    const response = await fetch(`/api/exchange-rate?from=${fromCurrency}&to=${toCurrency}`);
    const data = await response.json();
    
    const rate = data.rates[toCurrency];
    const result = amount * rate;
    
    document.getElementById('result').innerText = `Montan konvèti: ${result}`;
}
