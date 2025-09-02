# HTS Manager Brand Guidelines & Design Specifications

## Brand Colors

### Primary Palette
- **Primary Blue:** #1e40af (RGB: 30, 64, 175)
  - Use for: Main CTAs, headers, primary UI elements
  - Represents: Trust, professionalism, compliance
  
- **Secondary Gray:** #64748b (RGB: 100, 116, 139) 
  - Use for: Secondary text, borders, subtle UI elements
  - Represents: Sophistication, neutrality

- **Success Green:** #059669 (RGB: 5, 150, 105)
  - Use for: Success states, checkmarks, positive feedback
  - Represents: Compliance, success, approval

### Supporting Colors
- **Background White:** #ffffff
- **Text Dark:** #1f2937 (RGB: 31, 41, 55)
- **Border Light:** #e5e7eb (RGB: 229, 231, 235)
- **Accent Orange:** #ea580c (RGB: 234, 88, 12) - for urgency/deadlines

## Typography

### Primary Font Stack
```css
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
```

### Hierarchy
- **H1 Headlines:** 32px, Bold, #1f2937
- **H2 Subheadings:** 24px, Semi-bold, #1f2937  
- **H3 Section Headers:** 20px, Semi-bold, #1f2937
- **Body Text:** 16px, Regular, #374151
- **Small Text:** 14px, Regular, #6b7280
- **Button Text:** 16px, Semi-bold, #ffffff

## Logo & Icon Design Concepts

### Plugin Icon Ideas

#### Concept 1: Container + AI
- Shipping container silhouette
- Lightning bolt or AI symbol overlay
- Colors: Primary blue container, success green accent

#### Concept 2: Globe + Classification
- Stylized globe/world map
- Hierarchical tree or barcode overlay
- Colors: Primary blue globe, secondary gray details

#### Concept 3: WooCommerce Integration
- WooCommerce "W" as base
- Customs/classification elements integrated
- Colors: Match WooCommerce purple with our blue

#### Concept 4: HTS Symbol
- Abstract representation of classification hierarchy
- Clean, geometric design
- Colors: Primary blue with white/transparent background

### Banner Design Elements

#### Visual Motifs
- Shipping containers (international trade)
- Globe/world maps (global commerce)
- AI/automation symbols (lightning, gears)
- Classification trees (hierarchical structure)
- Customs seals/badges (official compliance)

#### Layout Template
```
[Left 1/3: Logo/Icon + Plugin Name]
[Center 1/3: Key benefit headline]
[Right 1/3: Urgency/deadline callout]
```

## Design System

### Spacing Scale
- **xs:** 4px
- **sm:** 8px  
- **md:** 16px
- **lg:** 24px
- **xl:** 32px
- **2xl:** 48px

### Border Radius
- **Small elements:** 4px
- **Buttons:** 6px
- **Cards:** 8px
- **Large containers:** 12px

### Shadows
```css
/* Subtle */
box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);

/* Medium */  
box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);

/* Strong */
box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
```

## WordPress.org Asset Specifications

### Banner Requirements
- **Standard:** 1544x500px
- **High-DPI:** 3088x1000px (2x)
- **Format:** PNG or JPG
- **Max size:** 1MB

### Icon Requirements  
- **Standard:** 128x128px
- **High-DPI:** 256x256px (2x)
- **Format:** PNG with transparency
- **Max size:** 500KB

### Screenshot Guidelines
- **Dimensions:** 1200x900px or 1280x960px (4:3 ratio)
- **Format:** PNG preferred for text clarity
- **Max size:** 1MB each
- **Background:** Clean WordPress admin interface

## Visual Style Guidelines

### Do's
✅ Use consistent spacing and typography
✅ Maintain high contrast for accessibility  
✅ Keep designs clean and professional
✅ Include subtle customs/trade imagery
✅ Use our brand colors consistently
✅ Ensure mobile responsiveness

### Don'ts
❌ Use overly complex graphics
❌ Mix too many fonts or colors
❌ Create cluttered layouts
❌ Use low-contrast color combinations
❌ Include outdated UI elements
❌ Forget about accessibility

## Asset Templates

### Button Styles
```css
/* Primary CTA */
background: #1e40af;
color: #ffffff;
padding: 12px 24px;
border-radius: 6px;
font-weight: 600;

/* Secondary Button */
background: transparent;
border: 2px solid #1e40af;
color: #1e40af;
padding: 10px 22px;
border-radius: 6px;
```

### Card/Container Styles
```css
background: #ffffff;
border: 1px solid #e5e7eb;
border-radius: 8px;
box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
padding: 24px;
```

## Implementation Guidelines

### For Developers
- All colors should use CSS custom properties
- Follow WordPress admin styling conventions
- Ensure WCAG 2.1 AA accessibility compliance
- Test on different screen sizes and resolutions
- Use semantic HTML markup

### For Designers
- Create designs at 2x resolution for crisp displays
- Include hover and active states for interactive elements
- Design for both light and dark admin themes
- Consider WordPress admin color schemes
- Test designs with actual plugin content

## File Organization

### Naming Conventions
```
hts-manager-icon-128x128.png
hts-manager-icon-256x256.png
hts-manager-banner-1544x500.png
hts-manager-banner-3088x1000.png
hts-manager-screenshot-1.png (through 6)
```

### Source Files
- Keep layered PSD/Sketch/Figma files
- Export PNG versions for WordPress.org
- Optimize file sizes without quality loss
- Maintain version control for design files

This brand system ensures consistent, professional presentation across all HTS Manager marketing materials while meeting WordPress.org technical requirements.