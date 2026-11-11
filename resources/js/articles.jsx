import React from 'react';
import { createRoot } from 'react-dom/client';
import Navbar from './components/layout/Navbar';
import { PublicFooter } from './components/layout/Footer';
import '../css/app.css';

const navbar = document.getElementById('article-navbar');
const footer = document.getElementById('article-footer');

createRoot(navbar).render(<Navbar />);
createRoot(footer).render(<PublicFooter contact={JSON.parse(footer.dataset.contact)} />);
