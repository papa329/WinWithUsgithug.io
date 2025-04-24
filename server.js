 const express = require('express');
 const mongoose = require('mongoose');
 const bodyParser = require('body-parser');
 require('dotenv').config();
 
 const app = express();
 
 app.use(bodyParser.json());
 
 mongoose.connect(process.env.DB_URI, { useNewUrlParser: true, useUnifiedTopology: true });
 
 app.listen(process.env.PORT, () => {
   console.log(`Sèvè k ap koute sou port ${process.env.PORT}`);
 });
 
 app.get('/', (req, res) => {
   res.send('Aplikasyon Echanj Lajan ap fonksyone!');
 });
 