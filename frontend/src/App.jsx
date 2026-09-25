import FeatureHighlights from './components/landing/FeatureHighlights'
import Hero from './components/landing/Hero'
import Navbar from './components/landing/Navbar'
import './App.css'
import AboutSection from './components/landing/AboutSection'
import RoleOverview from './components/landing/RoleOverview'
import FAQSection from './components/landing/FAQSection'
import CTASection from './components/landing/CTASection'
import Footer from './components/landing/Footer'

function App() {
  return (
    <div className="app">
      <Navbar />

      <main>
        <Hero />
        <FeatureHighlights />
        <AboutSection />
        <RoleOverview />
        <FAQSection />
        <CTASection />
      </main>

      <Footer />
    </div>
  )
}

export default App